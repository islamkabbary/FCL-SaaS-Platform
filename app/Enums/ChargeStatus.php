<?php

namespace App\Enums;

/**
 * Derived from the charge amount and the payments recorded against it.
 */
enum ChargeStatus: string
{
    case Outstanding = 'outstanding';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public static function for(int $amount, int $paid): self
    {
        return match (true) {
            $paid >= $amount => self::Paid,
            $paid > 0 => self::PartiallyPaid,
            default => self::Outstanding,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Outstanding => __('Outstanding'),
            self::PartiallyPaid => __('Partially paid'),
            self::Paid => __('Paid'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Outstanding => 'amber',
            self::PartiallyPaid => 'blue',
            self::Paid => 'green',
        };
    }
}
