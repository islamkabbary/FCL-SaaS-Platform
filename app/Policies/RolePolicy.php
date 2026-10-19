<?php

namespace App\Policies;

use App\Actions\Roles\PrivilegeGuard;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Spatie\Permission\Models\Role;

/**
 * Role management inside the current tenant (registered in AppServiceProvider,
 * because Role is a package model).
 */
class RolePolicy
{
    public function __construct(protected PrivilegeGuard $privilegeGuard) {}

    /**
     * Only tenant users are ever granted anything here (never a Platform Admin).
     */
    public function before(mixed $user): ?bool
    {
        return $user instanceof User ? null : false;
    }

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermissionTo(TenantPermission::RolesView->value);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermissionTo(TenantPermission::RolesManage->value);
    }

    public function update(User $actor, Role $role): Response
    {
        if (! $actor->hasPermissionTo(TenantPermission::RolesManage->value)) {
            return Response::deny();
        }

        if ($role->name === TenantRole::TenantAdmin->value) {
            return Response::deny(__('The Tenant Admin role is built in and cannot be modified.'));
        }

        return $this->privilegeGuard->canGrantPermissions($actor, $role->permissions->pluck('name'))
            ? Response::allow()
            : Response::deny(__('You cannot modify a role with permissions you do not have.'));
    }

    public function delete(User $actor, Role $role): Response
    {
        if (! $actor->hasPermissionTo(TenantPermission::RolesManage->value)) {
            return Response::deny();
        }

        if (TenantRole::isSystem($role->name)) {
            return Response::deny(__('Built-in roles cannot be deleted.'));
        }

        return $this->privilegeGuard->canGrantPermissions($actor, $role->permissions->pluck('name'))
            ? Response::allow()
            : Response::deny(__('You cannot delete a role with permissions you do not have.'));
    }
}
