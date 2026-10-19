<?php

namespace App\Enums;

enum PlatformAuditAction: string
{
    case TenantCreated = 'tenant.created';
    case TenantUpdated = 'tenant.updated';
    case TenantSuspended = 'tenant.suspended';
    case TenantReactivated = 'tenant.reactivated';

    /**
     * Get the human readable label for the action.
     */
    public function label(): string
    {
        return match ($this) {
            self::TenantCreated => __('Tenant created'),
            self::TenantUpdated => __('Details updated'),
            self::TenantSuspended => __('Access suspended'),
            self::TenantReactivated => __('Access reactivated'),
        };
    }

    /**
     * Get the badge color for the action.
     */
    public function color(): string
    {
        return match ($this) {
            self::TenantCreated => 'blue',
            self::TenantUpdated => 'zinc',
            self::TenantSuspended => 'red',
            self::TenantReactivated => 'green',
        };
    }
}
