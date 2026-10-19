<?php

namespace App\Billing;

/**
 * The result of a proration: the amount in minor units and the inputs it was computed from.
 */
final readonly class Proration
{
    public function __construct(
        public int $amount,
        public int $remainingDays,
        public int $totalDays,
    ) {}
}
