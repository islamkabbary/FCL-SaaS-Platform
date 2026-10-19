<?php

namespace App\Enums;

enum SeatRequestType: string
{
    /** Additional seats now, charged prorated until the renewal date. */
    case Increase = 'increase';

    /** A lower seat count for the next term; the current term is unchanged. */
    case Reduction = 'reduction';

    public function label(): string
    {
        return match ($this) {
            self::Increase => __('Additional seats'),
            self::Reduction => __('Reduction at renewal'),
        };
    }
}
