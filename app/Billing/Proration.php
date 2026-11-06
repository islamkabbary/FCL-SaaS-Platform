<?php

namespace App\Billing;

/**
 * The setup fee of seats added during a term: the amount in minor units and the months
 * it was computed from (the month already started counts in full).
 */
final readonly class Proration
{
    public function __construct(
        public int $amount,
        public int $remainingMonths,
        public int $totalMonths,
    ) {}
}
