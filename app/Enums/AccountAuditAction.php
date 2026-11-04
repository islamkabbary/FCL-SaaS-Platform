<?php

namespace App\Enums;

/**
 * Actions on a user's own account recorded in the tenant audit log (TenantAuditLog::record).
 */
enum AccountAuditAction: string
{
    case UsernameChanged = 'account.username.changed';

    /**
     * Get the human readable label for the action.
     */
    public function label(): string
    {
        return match ($this) {
            self::UsernameChanged => __('Username changed'),
        };
    }
}
