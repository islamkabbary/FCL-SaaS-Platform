<?php

namespace App\Actions\Platform;

use App\Actions\Impersonation\EndImpersonationsFor;
use App\Enums\PlatformAuditAction;
use App\Enums\TenantAccessStatus;
use App\Exceptions\InvalidTenantTransition;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Suspends a ready tenant's access to the application.
 *
 * Only the central tenant record changes: users, sessions, the tenant database and
 * its files are kept intact, and the provisioning status stays "ready". Requests to
 * the tenant are refused by EnsureTenantIsReady while the suspension lasts. Support
 * sessions in the tenant end immediately; they are not resumed by a reactivation.
 */
class SuspendTenant
{
    public function __construct(protected EndImpersonationsFor $endImpersonations) {}

    /**
     * @throws InvalidTenantTransition
     */
    public function handle(PlatformAdmin $actor, Tenant $tenant, string $reason): Tenant
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($actor, $tenant, $reason): Tenant {
            // Lock the row so two concurrent requests cannot both pass the state check.
            $tenant = Tenant::query()->whereKey($tenant->getTenantKey())->lockForUpdate()->firstOrFail();

            if (! $tenant->canBeSuspended()) {
                throw InvalidTenantTransition::cannotSuspend($tenant);
            }

            $tenant->forceFill([
                'suspended_at' => now(),
                'suspension_reason' => $reason,
            ])->save();

            PlatformAuditLog::record(
                $actor,
                $tenant,
                PlatformAuditAction::TenantSuspended,
                ['access_status' => TenantAccessStatus::Active->value],
                ['access_status' => TenantAccessStatus::Suspended->value],
                $reason,
            );

            $this->endImpersonations->suspendedTenant($tenant);

            return $tenant;
        });
    }
}
