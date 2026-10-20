<?php

namespace App\Actions\Tenancy;

use App\Enums\PlatformAuditAction;
use App\Enums\TenantStatus;
use App\Exceptions\TenantProvisioningFailed;
use App\Exceptions\TenantProvisioningInProgress;
use App\Models\Branch;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Throwable;

/**
 * Creates a tenant company and provisions everything it needs to be usable.
 *
 * Provisioning spans the central database, a newly created tenant database and
 * that database's schema, so it cannot be one atomic transaction. Each run is
 * therefore idempotent: a tenant left in the "failed" state can be provisioned
 * again and every step resumes safely. Nothing is ever dropped automatically.
 *
 * Only one run works on a tenant at a time: a run first claims the tenant with an atomic
 * update. A run that stopped without recording a result (a fatal error, a killed process
 * or a timeout) leaves the tenant "provisioning"; it can be claimed again once it has made
 * no progress for StaleAfterMinutes.
 */
class ProvisionTenant
{
    /**
     * After this long without progress, a "provisioning" tenant is considered abandoned.
     */
    public const StaleAfterMinutes = 30;

    public function __construct(protected SyncTenantAuthorization $syncTenantAuthorization) {}

    /**
     * Register a new tenant in the central database and provision it.
     *
     * The creation is audited together with the central record, before provisioning starts,
     * so it is in the trail even if the provisioning run never finishes.
     *
     * @param  array{company_name: string, subdomain: string, admin_name: string, admin_email: string}  $attributes
     *
     * @throws TenantProvisioningFailed
     * @throws TenantProvisioningInProgress
     */
    public function create(array $attributes, ?PlatformAdmin $createdBy = null): ProvisioningResult
    {
        $tenant = DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($attributes, $createdBy): Tenant {
            $tenant = Tenant::create([
                'name' => $attributes['company_name'],
                'status' => TenantStatus::Pending,
                'initial_admin_name' => $attributes['admin_name'],
                'initial_admin_email' => Str::lower($attributes['admin_email']),
            ]);

            $domain = $tenant->domains()->create(['domain' => Str::lower($attributes['subdomain'])]);

            if ($createdBy !== null) {
                PlatformAuditLog::record($createdBy, $tenant, PlatformAuditAction::TenantCreated, newValues: [
                    'name' => $tenant->name,
                    'subdomain' => $domain->domain,
                    'status' => TenantStatus::Pending->value,
                ]);
            }

            return $tenant;
        });

        return $this->provision($tenant);
    }

    /**
     * Provision (or resume provisioning of) an existing tenant.
     *
     * @throws TenantProvisioningFailed
     * @throws TenantProvisioningInProgress
     */
    public function provision(Tenant $tenant): ProvisioningResult
    {
        if ($tenant->isReady()) {
            throw new LogicException("Tenant [{$tenant->id}] is already provisioned.");
        }

        $this->claim($tenant);

        try {
            $this->createDatabase($tenant);
            $this->migrateDatabase($tenant);
            $temporaryPassword = $this->seedTenantDatabase($tenant);
        } catch (Throwable $exception) {
            $this->markAsFailed($tenant, $exception);

            throw new TenantProvisioningFailed($tenant, class_basename($exception));
        }

        $tenant->update([
            'status' => TenantStatus::Ready,
            'provisioned_at' => now(),
        ]);

        return new ProvisioningResult($tenant, $tenant->initial_admin_email, $temporaryPassword);
    }

    /**
     * Take the tenant for this run with one atomic update: pending and failed tenants, and
     * tenants whose provisioning stopped making progress, can be claimed; a tenant another
     * run is working on cannot. Two runs at once would migrate and seed twice and leave one
     * admin holding a temporary password that no longer works.
     *
     * @throws TenantProvisioningInProgress
     */
    protected function claim(Tenant $tenant): void
    {
        $claimed = Tenant::query()
            ->whereKey($tenant->getTenantKey())
            ->where(function ($query): void {
                $query->whereIn('status', [TenantStatus::Pending, TenantStatus::Failed])
                    ->orWhere(fn ($stale) => $stale->where('status', TenantStatus::Provisioning)
                        ->where('updated_at', '<', now()->subMinutes(self::StaleAfterMinutes)));
            })
            ->update(['status' => TenantStatus::Provisioning, 'provisioning_error' => null]);

        $tenant->refresh();

        if ($claimed !== 1) {
            if ($tenant->isReady()) {
                throw new LogicException("Tenant [{$tenant->id}] is already provisioned.");
            }

            throw new TenantProvisioningInProgress($tenant);
        }
    }

    /**
     * Create the tenant's dedicated database unless a previous run already did.
     */
    protected function createDatabase(Tenant $tenant): void
    {
        $database = $tenant->database();
        $database->makeCredentials();

        if ($database->manager()->databaseExists($database->getName())) {
            return;
        }

        app()->call([new CreateDatabase($tenant), 'handle']);
    }

    /**
     * Run the tenant migrations against the tenant's database.
     */
    protected function migrateDatabase(Tenant $tenant): void
    {
        $exitCode = Artisan::call('tenants:migrate', [
            '--tenants' => [$tenant->getTenantKey()],
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException("Tenant migrations exited with code {$exitCode}.");
        }
    }

    /**
     * Create the Default Branch, the roles and permissions, and the initial Tenant Admin
     * (holding the Tenant Admin role) inside the tenant database.
     *
     * Returns the admin's newly generated temporary password.
     */
    protected function seedTenantDatabase(Tenant $tenant): string
    {
        tenancy()->initialize($tenant);

        try {
            return DB::transaction(function () use ($tenant): string {
                $branch = Branch::query()->firstOrCreate(
                    ['code' => Branch::DefaultCode],
                    ['name' => 'Main Branch', 'is_default' => true],
                );

                $temporaryPassword = Str::password(20);

                User::query()
                    ->firstOrNew(['email' => $tenant->initial_admin_email])
                    ->forceFill([
                        'name' => $tenant->initial_admin_name,
                        'password' => $temporaryPassword,
                        'primary_branch_id' => $branch->id,
                        'is_tenant_admin' => true,
                        'must_change_password' => true,
                        'email_verified_at' => now(),
                    ])
                    ->save();

                // Roles and permissions; gives the Tenant Admin role to the admin above.
                $this->syncTenantAuthorization->handle();

                return $temporaryPassword;
            });
        } finally {
            tenancy()->end();
        }
    }

    /**
     * Record the failure on the central tenant record without leaking sensitive data.
     */
    protected function markAsFailed(Tenant $tenant, Throwable $exception): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $tenant->update([
            'status' => TenantStatus::Failed,
            'provisioning_error' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 1000),
        ]);

        Log::error('Tenant provisioning failed.', [
            'tenant_id' => $tenant->id,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
