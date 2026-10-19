<?php

namespace App\Enums;

/**
 * Whether the platform allows a tenant's users into the application. Independent of
 * the provisioning TenantStatus: only a ready tenant that is active can be accessed.
 */
enum TenantAccessStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    /**
     * Get the human readable label for the access status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Suspended => __('Suspended'),
        };
    }

    /**
     * Get the badge color for the access status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Suspended => 'red',
        };
    }
}
