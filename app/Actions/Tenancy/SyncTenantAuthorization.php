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
 * - Creates every permission of the application catalog. A renamed permission
 *   (TenantPermission::renamed()) keeps its grants under the new name; one removed from the
 *   catalog is deleted with its grants.
 * - Creates the built-in roles; the Tenant Admin role always holds every permission.
 *   The Employee role is created without permissions and is never reset afterwards,
 *   so a Tenant Admin's customizations survive later runs.
 * - Gives the Tenant Admin role to the initial Tenant Admin(s) provisioned before roles
 *   existed (users flagged with the legacy is_tenant_admin column), only while nobody in
 *   the tenant holds that role yet.
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
            $this->applyRenames();

            foreach (TenantPermission::values() as $permission) {
                Permission::findOrCreate($permission, self::Guard);
            }

            // A permission retired from the catalog grants nothing; deleting it (and, through
            // the foreign keys, its grants) keeps the role editor and the privilege checks
            // consistent with the catalog.
            Permission::query()
                ->where('guard_name', self::Guard)
                ->whereNotIn('name', TenantPermission::values())
                ->get()
                ->each(fn (Permission $permission): ?bool => $permission->delete());

            Role::findOrCreate(TenantRole::TenantAdmin->value, self::Guard)
                ->syncPermissions(TenantPermission::values());

            Role::findOrCreate(TenantRole::Employee->value, self::Guard);

            // Only a tenant that has no Tenant Admin at all predates roles. Once anyone holds the
            // role, roles are managed by the Tenant Admins, and a founder they demoted (the flag
            // is never cleared) must not be promoted again on the next deployment.
            if (! User::query()->role(TenantRole::TenantAdmin->value, self::Guard)->exists()) {
                User::query()
                    ->where('is_tenant_admin', true)
                    ->each(fn (User $user) => $user->assignRole(TenantRole::TenantAdmin->value));
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Old name => new name of renamed permissions.
     *
     * @return array<string, string>
     */
    protected function renamedPermissions(): array
    {
        return TenantPermission::renamed();
    }

    /**
     * Move every grant (roles and direct user grants) of a renamed permission to its new
     * name. Renamed in place when the new name does not exist yet; merged otherwise.
     */
    protected function applyRenames(): void
    {
        foreach ($this->renamedPermissions() as $from => $to) {
            $old = Permission::query()->where('name', $from)->where('guard_name', self::Guard)->first();

            if ($old === null) {
                continue;
            }

            $new = Permission::query()->where('name', $to)->where('guard_name', self::Guard)->first();

            if ($new === null) {
                $old->forceFill(['name' => $to])->save();

                continue;
            }

            $old->roles->each(fn (Role $role): Role => $role->givePermissionTo($new));
            User::query()
                ->whereHas('permissions', fn ($query) => $query->whereKey($old->getKey()))
                ->get()
                ->each(fn (User $user): User => $user->givePermissionTo($new));
            $old->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
