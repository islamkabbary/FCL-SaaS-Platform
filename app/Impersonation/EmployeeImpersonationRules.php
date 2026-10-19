<?php

namespace App\Impersonation;

use App\Actions\Roles\PrivilegeGuard;
use App\Models\User;

/**
 * Who a Tenant Admin may view the application as. Both users come from the current
 * tenant database, so a user of another tenant can never be found or targeted.
 */
class EmployeeImpersonationRules
{
    public function __construct(protected PrivilegeGuard $privilegeGuard) {}

    /**
     * Why the actor may not impersonate the target, or null when they may.
     */
    public function denialReason(User $actor, User $target): ?string
    {
        if (! $actor->is_active || ! $actor->isTenantAdmin()) {
            return __('Only an active Tenant Admin can view the application as an employee.');
        }

        if ($actor->is($target)) {
            return __('You cannot view the application as yourself.');
        }

        if (! $target->is_active) {
            return __('Only active employees can be selected.');
        }

        if ($target->isTenantAdmin()) {
            return __('You cannot view the application as another Tenant Admin.');
        }

        // Equal or higher privileges: the target holds every permission the actor holds, or more.
        if ($this->privilegeGuard->outranks($target, $actor)
            || $this->privilegeGuard->canGrantPermissions($target, $actor->getAllPermissions()->pluck('name'))) {
            return __('You cannot view the application as a user with privileges equal to or higher than yours.');
        }

        return null;
    }
}
