<?php

namespace App\Actions\Employees;

use App\Actions\Roles\PrivilegeGuard;
use App\Enums\TenantRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

/**
 * Replace the roles of an employee, enforcing the privilege-escalation rules.
 *
 * Roles are looked up in the current tenant database only, so an ID from another
 * tenant can never be attached.
 */
class SyncEmployeeRoles
{
    public function __construct(protected PrivilegeGuard $privilegeGuard) {}

    /**
     * @param  list<int>  $roleIds
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, User $employee, array $roleIds, bool $isNewEmployee = false): void
    {
        if (! $isNewEmployee) {
            Gate::forUser($actor)->authorize('assignRoles', $employee);
        }

        $roles = Role::query()->with('permissions')->whereKey($roleIds)->get();

        if ($roles->count() !== count(array_unique($roleIds))) {
            throw new AuthorizationException(__('One or more selected roles do not exist.'));
        }

        $currentRoleNames = $employee->roles()->pluck('name');

        foreach ($roles->whereNotIn('name', $currentRoleNames) as $role) {
            if (! $this->privilegeGuard->canAssignRole($actor, $role)) {
                throw new AuthorizationException(__('You cannot assign the ":role" role.', ['role' => $role->name]));
            }
        }

        $removesTenantAdmin = $currentRoleNames->contains(TenantRole::TenantAdmin->value)
            && ! $roles->contains('name', TenantRole::TenantAdmin->value);

        if ($removesTenantAdmin && $employee->isLastActiveTenantAdmin()) {
            throw new AuthorizationException(__('The last active Tenant Admin must keep the Tenant Admin role.'));
        }

        $employee->syncRoles($roles);
    }
}
