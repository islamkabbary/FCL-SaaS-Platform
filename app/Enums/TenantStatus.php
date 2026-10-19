<?php

namespace App\Enums;

enum TenantStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Ready = 'ready';
    case Failed = 'failed';

    /**
     * Get the human readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Provisioning => __('Provisioning'),
            self::Ready => __('Ready'),
            self::Failed => __('Failed'),
        };
    }

    /**
     * Get the badge color for the status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Ready => 'green',
            self::Failed => 'red',
            self::Provisioning => 'amber',
            self::Pending => 'zinc',
        };
    }
}
