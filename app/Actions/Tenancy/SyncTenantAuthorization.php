<?php

namespace App\Actions\Tenancy;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings the current tenant's roles and permissions up to date. Safe to run any number of times.
 *
 * - Creates every permission of the application catalog.
 * - Creates the built-in roles; the Tenant Admin role always holds every permission.
 *   The Employee role is created without permissions and is never reset afterwards,
 *   so a Tenant Admin's customizations survive later runs.
 * - Gives the Tenant Admin role to the initial Tenant Admin(s) provisioned before roles
 *   existed (users flagged with the legacy is_tenant_admin column).
 *
 * Must run in tenant context: all records are written to the tenant database.
 */
class SyncTenantAuthorization
{
    public const Guard = 'web';

    public function handle(): void
    {
        if (! tenancy()->initialized) {
            throw new LogicException('Tenant authorization can only be synchronized in tenant context.');
        }

        DB::transaction(function (): void {
            foreach (TenantPermission::values() as $permission) {
                Permission::findOrCreate($permission, self::Guard);
            }

            Role::findOrCreate(TenantRole::TenantAdmin->value, self::Guard)
                ->syncPermissions(TenantPermission::values());

            Role::findOrCreate(TenantRole::Employee->value, self::Guard);

            User::query()
                ->where('is_tenant_admin', true)
                ->withoutRole(TenantRole::TenantAdmin->value, self::Guard)
                ->each(fn (User $user) => $user->assignRole(TenantRole::TenantAdmin->value));
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
