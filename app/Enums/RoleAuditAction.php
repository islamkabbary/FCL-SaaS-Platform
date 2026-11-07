<?php

namespace App\Enums;

/**
 * Changes to roles recorded in the tenant audit log (TenantAuditLog::record): who changed
 * which role, and its name and permissions before and after.
 */
enum RoleAuditAction: string
{
    case RoleCreated = 'role.created';
    case RoleUpdated = 'role.updated';
    case RoleDeleted = 'role.deleted';

    /**
     * Get the human readable label for the action.
     */
    public function label(): string
    {
        return match ($this) {
            self::RoleCreated => __('Role created'),
            self::RoleUpdated => __('Role updated'),
            self::RoleDeleted => __('Role deleted'),
        };
    }
}
