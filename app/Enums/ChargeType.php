<?php

namespace App\Enums;

enum ChargeType: string
{
    case Initial = 'initial';
    case Renewal = 'renewal';
    case SeatIncrease = 'seat_increase';

    public function label(): string
    {
        return match ($this) {
            self::Initial => __('Initial annual subscription'),
            self::Renewal => __('Annual renewal'),
            self::SeatIncrease => __('Additional seats (prorated)'),
        };
    }
}
