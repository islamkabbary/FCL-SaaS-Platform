<?php

namespace App\Billing;

/**
 * Purchased seats versus active users. A null limit means unlimited (legacy tenant).
 *
 * When a later term is already confirmed (an early renewal) with fewer seats than the
 * current one, no more users may be activated than that later term allows: otherwise the
 * tenant would enter it with more active users than it pays for.
 */
final readonly class SeatUsage
{
    public function __construct(
        public ?int $limit,
        public int $used,
        public ?int $confirmedLaterTermSeats = null,
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
        if ($this->limit === null) {
            return PHP_INT_MAX;
        }

        return max(0, min($this->limit, $this->confirmedLaterTermSeats ?? $this->limit) - $this->used);
    }

    /**
     * Whether a confirmed later term, rather than the current term, limits new users.
     */
    public function isCappedByLaterTerm(): bool
    {
        return $this->limit !== null && $this->confirmedLaterTermSeats !== null && $this->confirmedLaterTermSeats < $this->limit;
    }

    /**
     * Active users above the purchased seats (e.g. after a reduction at renewal).
     */
    public function overLimit(): int
    {
        return $this->limit === null ? 0 : max(0, $this->used - $this->limit);
    }
}
