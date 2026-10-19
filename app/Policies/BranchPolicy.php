<?php

namespace App\Policies;

use App\Models\User;

class BranchPolicy
{
    /**
     * Determine whether the user can create branches.
     *
     * Branches are provisioned by the platform only; tenant users,
     * including the Tenant Admin, cannot create additional branches.
     */
    public function create(User $user): bool
    {
        return false;
    }
}
