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
 * - Creates every permission of the application catalog. A renamed or split permission
 *   (TenantPermission::renamed()) gives each of its new names to everyone who held it;
 *   one removed from the catalog is deleted with its grants.
 * - Renames the former "Tenant Admin" role to "Super Admin", keeping its holders. The Super
 *   Admin role holds no permission rows: it is exempt from every permission check.
 * - Creates the other built-in roles with their default permissions, only when they do not
 *   exist yet, so a Super Admin's later changes survive.
 * - Gives the Super Admin role to the initial owner(s) provisioned before roles existed
 *   (users flagged with the legacy is_tenant_admin column), only while nobody in the
 *   tenant holds that role yet.
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

            $this->applyRenames();

            // A permission retired from the catalog grants nothing; deleting it (and, through
            // the foreign keys, its grants) keeps the role editor and the privilege checks
            // consistent with the catalog.
            Permission::query()
                ->where('guard_name', self::Guard)
                ->whereNotIn('name', TenantPermission::values())
                ->get()
                ->each(fn (Permission $permission): ?bool => $permission->delete());

            $this->renameLegacySuperAdminRole();

            foreach (TenantRole::cases() as $builtIn) {
                $role = Role::findOrCreate($builtIn->value, self::Guard);

                if ($role->wasRecentlyCreated) {
                    $role->syncPermissions(array_map(fn (TenantPermission $permission): string => $permission->value, $builtIn->defaultPermissions()));
                }
            }

            // Exempt from permission checks: rows would only suggest it is limited by them.
            Role::findByName(TenantRole::SuperAdmin->value, self::Guard)->syncPermissions([]);

            // Only a tenant that has no Super Admin at all predates roles. Once anyone holds the
            // role, roles are managed by the Super Admins, and a founder they demoted (the flag
            // is never cleared) must not be promoted again on the next deployment.
            if (! User::query()->role(TenantRole::SuperAdmin->value, self::Guard)->exists()) {
                User::query()
                    ->where('is_tenant_admin', true)
                    ->each(fn (User $user) => $user->assignRole(TenantRole::SuperAdmin->value));
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Old name => new names of renamed or split permissions.
     *
     * @return array<string, list<string>>
     */
    protected function renamedPermissions(): array
    {
        return TenantPermission::renamed();
    }

    /**
     * Give every grant (roles and direct user grants) of a renamed permission to each of its
     * new names, then delete the old name. The new names already exist at this point.
     */
    protected function applyRenames(): void
    {
        foreach ($this->renamedPermissions() as $from => $targets) {
            $old = Permission::query()->where('name', $from)->where('guard_name', self::Guard)->first();

            if ($old === null) {
                continue;
            }

            $new = Permission::query()->whereIn('name', $targets)->where('guard_name', self::Guard)->get();

            $old->roles->each(fn (Role $role): Role => $role->givePermissionTo($new));
            User::query()
                ->whereHas('permissions', fn ($query) => $query->whereKey($old->getKey()))
                ->get()
                ->each(fn (User $user): User => $user->givePermissionTo($new));
            $old->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The built-in owner role was called "Tenant Admin". Rename it in place, so its holders
     * keep it; merge them if both roles exist.
     */
    protected function renameLegacySuperAdminRole(): void
    {
        $legacy = Role::query()->where('name', TenantRole::LegacySuperAdminName)->where('guard_name', self::Guard)->first();

        if ($legacy === null) {
            return;
        }

        $current = Role::query()->where('name', TenantRole::SuperAdmin->value)->where('guard_name', self::Guard)->first();

        if ($current === null) {
            $legacy->forceFill(['name' => TenantRole::SuperAdmin->value])->save();
        } else {
            User::query()->role($legacy->name, self::Guard)->get()
                ->each(fn (User $user): User => $user->assignRole($current));
            $legacy->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
