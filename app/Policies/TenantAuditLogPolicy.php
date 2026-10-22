<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Impersonation\ActiveImpersonation;
use App\Models\TenantAuditLog;
use App\Models\User;

/**
 * Reading the company audit log. Records only ever come from the current tenant database.
 * Nobody may create, change or delete records through a policy: they are written by the
 * audited actions themselves (TenantAuditLog::record).
 */
class TenantAuditLogPolicy
{
    /**
     * Only tenant users are ever granted anything here (never a Platform Admin).
     */
    public function before(mixed $user): ?bool
    {
        return $user instanceof User ? null : false;
    }

    public function viewAny(User $actor): bool
    {
        return $this->mayRead($actor);
    }

    public function view(User $actor, TenantAuditLog $record): bool
    {
        return $this->mayRead($actor);
    }

    /**
     * Not during an impersonation, whatever the impersonated user's permissions: support
     * access to the audit log has not been approved. RestrictionPolicy denies it as well.
     */
    protected function mayRead(User $actor): bool
    {
        $active = ActiveImpersonation::current();

        if ($active !== null && $active->targetUserId() === $actor->getKey()) {
            return false;
        }

        return $actor->is_active && $actor->hasPermissionTo(TenantPermission::AuditView->value);
    }
}
