<?php

namespace App\Enums;

enum ChargeType: string
{
    case Setup = 'setup';
    case Monthly = 'monthly';
    case SeatSetup = 'seat_setup';

    public function label(): string
    {
        return match ($this) {
            self::Setup => __('Setup fee'),
            self::Monthly => __('Monthly subscription'),
            self::SeatSetup => __('Setup fee for additional seats'),
        };
    }
}
