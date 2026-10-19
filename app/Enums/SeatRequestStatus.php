<?php

namespace App\Enums;

enum SeatRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** An approved reduction replaced by a later approved reduction before renewal. */
    case Superseded = 'superseded';

    /** An approved reduction that was applied at renewal. */
    case Applied = 'applied';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending review'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
            self::Cancelled => __('Cancelled'),
            self::Superseded => __('Superseded'),
            self::Applied => __('Applied at renewal'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved, self::Applied => 'green',
            self::Rejected => 'red',
            self::Cancelled, self::Superseded => 'zinc',
        };
    }
}
