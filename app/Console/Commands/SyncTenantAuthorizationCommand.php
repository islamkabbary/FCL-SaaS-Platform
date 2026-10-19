<?php

namespace App\Console\Commands;

use App\Actions\Tenancy\SyncTenantAuthorization;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tenants:sync-authorization {--tenant=* : Tenant ID or subdomain (defaults to every ready tenant)}')]
#[Description('Create or update the roles and permissions of tenants (idempotent; run after tenants:migrate)')]
class SyncTenantAuthorizationCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SyncTenantAuthorization $syncTenantAuthorization): int
    {
        $identifiers = array_values(array_filter((array) $this->option('tenant'), 'is_string'));

        $query = Tenant::query()->where('status', TenantStatus::Ready);

        if ($identifiers !== []) {
            $query->where(fn ($query) => $query
                ->whereIn('id', $identifiers)
                ->orWhereHas('domains', fn ($query) => $query->whereIn('domain', $identifiers)));
        }

        $matched = 0;
        $failed = false;

        foreach ($query->cursor() as $tenant) {
            $matched++;

            try {
                $tenant->run(fn () => $syncTenantAuthorization->handle());
                $this->components->info("Synchronized [{$tenant->primaryDomain()?->domain}].");
            } catch (Throwable $exception) {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }

                $failed = true;
                $this->components->error("Failed [{$tenant->primaryDomain()?->domain}]: {$exception->getMessage()}");
            }
        }

        if ($matched === 0) {
            $this->components->warn('No ready tenants matched.');

            return self::SUCCESS;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
