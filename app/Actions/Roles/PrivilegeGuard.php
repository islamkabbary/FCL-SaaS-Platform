<?php

namespace App\Actions\Roles;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * Rules that prevent privilege escalation inside a tenant.
 *
 * A user can only grant permissions they hold themselves, and can never act on a
 * user or role whose permissions exceed their own. The built-in Super Admin role
 * can only be granted by a Super Admin.
 */
class PrivilegeGuard
{
    /**
     * Determine whether the actor holds every one of the given permissions.
     *
     * @param  iterable<string>  $permissionNames
     */
    public function canGrantPermissions(User $actor, iterable $permissionNames): bool
    {
        // Only catalog permissions count: one retired from the catalog grants nothing, and
        // must not make a role or its holders untouchable because nobody holds it anymore.
        return collect($permissionNames)
            ->intersect(TenantPermission::values())
            ->diff($this->permissionNamesOf($actor))
            ->isEmpty();
    }

    /**
     * Determine whether the actor may give the role to someone.
     */
    public function canAssignRole(User $actor, Role $role): bool
    {
        if ($role->name === TenantRole::SuperAdmin->value && ! $actor->isSuperAdmin()) {
            return false;
        }

        return $this->canGrantPermissions($actor, $role->permissions->pluck('name'));
    }

    /**
     * Determine whether the target holds any permission the actor does not.
     */
    public function outranks(User $target, User $actor): bool
    {
        if ($target->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            return true;
        }

        return ! $this->canGrantPermissions($actor, $this->permissionNamesOf($target));
    }

    /**
     * The catalog permissions the user holds. A Super Admin holds them all, without rows.
     *
     * @return Collection<int, string>
     */
    public function permissionNamesOf(User $user): Collection
    {
        if ($user->isSuperAdmin()) {
            return collect(TenantPermission::values());
        }

        return $user->getAllPermissions()->pluck('name')->intersect(TenantPermission::values())->values();
    }
}
