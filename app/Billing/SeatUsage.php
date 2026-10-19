<?php

namespace App\Billing;

/**
 * Purchased seats versus active users. A null limit means unlimited (legacy tenant).
 */
final readonly class SeatUsage
{
    public function __construct(
        public ?int $limit,
        public int $used,
    ) {}

    public function isUnlimited(): bool
    {
        return $this->limit === null;
    }

    /**
     * Seats that can still be assigned (PHP_INT_MAX when unlimited, never negative).
     */
    public function available(): int
    {
        return $this->limit === null ? PHP_INT_MAX : max(0, $this->limit - $this->used);
    }

    /**
     * Active users above the purchased seats (e.g. after a reduction at renewal).
     */
    public function overLimit(): int
    {
        return $this->limit === null ? 0 : max(0, $this->used - $this->limit);
    }
}
