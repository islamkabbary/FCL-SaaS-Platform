<?php

namespace App\Actions\Platform;

use App\Enums\PlatformAuditAction;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Updates the administrative details of a tenant kept in the central database.
 *
 * Only the company name is editable: the subdomain and the database name identify
 * the tenant and are never changed here, and nothing inside the tenant database is touched.
 */
class UpdateTenantDetails
{
    /**
     * Fields a Platform Admin may change.
     *
     * @var list<string>
     */
    public const Editable = ['name'];

    /**
     * @param  array<string, mixed>  $attributes  Validated input; keys outside Editable are ignored.
     */
    public function handle(PlatformAdmin $actor, Tenant $tenant, array $attributes): Tenant
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($actor, $tenant, $attributes): Tenant {
            $tenant = Tenant::query()->whereKey($tenant->getTenantKey())->lockForUpdate()->firstOrFail();

            $tenant->fill(array_intersect_key($attributes, array_flip(self::Editable)));

            if (! $tenant->isDirty(self::Editable)) {
                return $tenant;
            }

            $changed = array_values(array_intersect(self::Editable, array_keys($tenant->getDirty())));
            $oldValues = array_intersect_key($tenant->getOriginal(), array_flip($changed));

            $tenant->save();

            PlatformAuditLog::record(
                $actor,
                $tenant,
                PlatformAuditAction::TenantUpdated,
                $oldValues,
                array_intersect_key($tenant->getAttributes(), array_flip($changed)),
            );

            return $tenant;
        });
    }
}
