<?php

namespace App\Actions\Roles;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

/**
 * Create a custom role, or update a role's name and permissions.
 *
 * Only permissions from the application catalog can be used, and only those the
 * actor holds. Built-in roles keep their names; the Tenant Admin role is locked.
 */
class SaveRole
{
    public function __construct(protected PrivilegeGuard $privilegeGuard) {}

    /**
     * @param  list<string>  $permissionNames
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, ?Role $role, string $name, array $permissionNames): Role
    {
        $role === null
            ? Gate::forUser($actor)->authorize('create', Role::class)
            : Gate::forUser($actor)->authorize('update', $role);

        $permissionNames = array_values(array_unique($permissionNames));

        if (array_diff($permissionNames, TenantPermission::values()) !== []) {
            throw new AuthorizationException(__('Unknown permissions cannot be assigned.'));
        }

        if (! $this->privilegeGuard->canGrantPermissions($actor, $permissionNames)) {
            throw new AuthorizationException(__('You cannot grant permissions you do not have.'));
        }

        $name = TenantRole::normalizeName($name);

        if ($role !== null && TenantRole::isSystem($role->name) && $role->name !== $name) {
            throw new AuthorizationException(__('Built-in roles cannot be renamed.'));
        }

        // Creating or renaming a custom role to a built-in name (or a look-alike) would make
        // its holders pass for Tenant Admins, and the next sync would adopt the role.
        if (($role === null || ! TenantRole::isSystem($role->name)) && TenantRole::isReservedName($name)) {
            throw new AuthorizationException(__('This role name is reserved.'));
        }

        return DB::transaction(function () use ($role, $name, $permissionNames): Role {
            $role ??= new Role(['guard_name' => 'web']);
            $role->name = $name;
            $role->save();

            $role->syncPermissions($permissionNames);

            return $role;
        });
    }
}
