<?php

namespace App\Policies;

use App\Actions\Roles\PrivilegeGuard;
use App\Enums\TenantPermission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Employee management inside the current tenant. User records only ever come from
 * the current tenant database, so these rules never see another tenant's users.
 */
class UserPolicy
{
    public function __construct(protected PrivilegeGuard $privilegeGuard) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo(TenantPermission::EmployeesView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo(TenantPermission::EmployeesCreate->value);
    }

    public function update(User $actor, User $employee): Response
    {
        if (! $actor->hasPermissionTo(TenantPermission::EmployeesUpdate->value)) {
            return Response::deny();
        }

        return $this->privilegeGuard->outranks($employee, $actor)
            ? Response::deny(__('You cannot edit a user with more privileges than you.'))
            : Response::allow();
    }

    public function deactivate(User $actor, User $employee): Response
    {
        if (! $actor->hasPermissionTo(TenantPermission::EmployeesDeactivate->value)) {
            return Response::deny();
        }

        if ($actor->is($employee)) {
            return Response::deny(__('You cannot deactivate your own account.'));
        }

        if ($this->privilegeGuard->outranks($employee, $actor)) {
            return Response::deny(__('You cannot deactivate a user with more privileges than you.'));
        }

        return $employee->isLastActiveTenantAdmin()
            ? Response::deny(__('The last active Tenant Admin cannot be deactivated.'))
            : Response::allow();
    }

    public function activate(User $actor, User $employee): Response
    {
        if (! $actor->hasPermissionTo(TenantPermission::EmployeesDeactivate->value)) {
            return Response::deny();
        }

        return $this->privilegeGuard->outranks($employee, $actor)
            ? Response::deny(__('You cannot activate a user with more privileges than you.'))
            : Response::allow();
    }

    /**
     * Change which roles the employee holds.
     */
    public function assignRoles(User $actor, User $employee): Response
    {
        if (! $actor->hasPermissionTo(TenantPermission::RolesAssign->value)) {
            return Response::deny();
        }

        if ($actor->is($employee)) {
            return Response::deny(__('You cannot change your own roles.'));
        }

        return $this->privilegeGuard->outranks($employee, $actor)
            ? Response::deny(__('You cannot change the roles of a user with more privileges than you.'))
            : Response::allow();
    }
}
