<?php

namespace App\Actions\Platform;

use App\Enums\PlatformAuditAction;
use App\Enums\TenantAccessStatus;
use App\Exceptions\InvalidTenantTransition;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Lifts a tenant's suspension. Nothing is re-provisioned: the tenant database, its
 * users, roles and data are exactly as they were when the tenant was suspended.
 */
class ReactivateTenant
{
    /**
     * @throws InvalidTenantTransition
     */
    public function handle(PlatformAdmin $actor, Tenant $tenant, ?string $reason = null): Tenant
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($actor, $tenant, $reason): Tenant {
            $tenant = Tenant::query()->whereKey($tenant->getTenantKey())->lockForUpdate()->firstOrFail();

            if (! $tenant->canBeReactivated()) {
                throw InvalidTenantTransition::cannotReactivate();
            }

            $oldValues = [
                'access_status' => TenantAccessStatus::Suspended->value,
                'suspended_at' => $tenant->suspended_at?->toIso8601String(),
                'suspension_reason' => $tenant->suspension_reason,
            ];

            $tenant->forceFill([
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();

            PlatformAuditLog::record(
                $actor,
                $tenant,
                PlatformAuditAction::TenantReactivated,
                $oldValues,
                ['access_status' => TenantAccessStatus::Active->value],
                $reason,
            );

            return $tenant;
        });
    }
}
