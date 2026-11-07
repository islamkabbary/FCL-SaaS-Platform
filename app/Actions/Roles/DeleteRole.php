<?php

namespace App\Actions\Roles;

use App\Enums\RoleAuditAction;
use App\Models\TenantAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

/**
 * Delete a custom role. Employees holding it lose the role's permissions.
 */
class DeleteRole
{
    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, Role $role): void
    {
        Gate::forUser($actor)->authorize('delete', $role);

        DB::transaction(function () use ($actor, $role): void {
            $old = SaveRole::snapshot($role->name, $role->permissions->pluck('name')->all());

            TenantAuditLog::record(RoleAuditAction::RoleDeleted, $actor, $role, $old, null, metadata: ['holders' => $role->users()->count()]);

            $role->delete();
        });
    }
}
