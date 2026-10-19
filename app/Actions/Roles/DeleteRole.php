<?php

namespace App\Actions\Roles;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
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

        $role->delete();
    }
}
