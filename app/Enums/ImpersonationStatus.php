<?php

namespace App\Enums;

/**
 * Derived state of a support or employee impersonation session; never stored.
 */
enum ImpersonationStatus: string
{
    /** Support only: created, the handoff to the tenant has not been used yet. */
    case Pending = 'pending';
    case Active = 'active';
    case Expired = 'expired';
    case Ended = 'ended';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Active => __('Active'),
            self::Expired => __('Expired'),
            self::Ended => __('Ended'),
            self::Revoked => __('Revoked'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'blue',
            self::Active => 'green',
            self::Expired, self::Ended => 'zinc',
            self::Revoked => 'red',
        };
    }
}
