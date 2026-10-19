<?php

namespace App\Exceptions;

use App\Billing\SeatUsage;
use RuntimeException;

/**
 * Thrown when activating one more user would exceed the purchased seats.
 */
class NoSeatAvailable extends RuntimeException
{
    public static function forUsage(SeatUsage $usage): self
    {
        if ($usage->limit === 0) {
            return new self(__('Your company has no active subscription, so no users can be added or reactivated.'));
        }

        return new self(__('All :limit purchased seats are in use (:used active users). Deactivate a user or request additional seats.', [
            'limit' => $usage->limit,
            'used' => $usage->used,
        ]));
    }

    public static function busy(): self
    {
        return new self(__('Another seat change is in progress. Please try again.'));
    }
}
