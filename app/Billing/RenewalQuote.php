<?php

namespace App\Billing;

use App\Models\SeatChangeRequest;
use App\Models\SubscriptionTerm;
use Carbon\CarbonImmutable;

/**
 * Everything a Platform Admin needs to review before confirming a renewal.
 *
 * A renewal charges no setup fee for seats that already paid one; only the seats above
 * the subscription's "setup_paid_seats" ($setupSeats) pay it, for a full year.
 */
final readonly class RenewalQuote
{
    /**
     * @param  list<string>  $errors  Reasons the renewal cannot be confirmed as proposed.
     */
    public function __construct(
        public SubscriptionTerm $previousTerm,
        public int $seatPrice,
        public int $seats,
        public int $setupSeats,
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
        public int $activeUsers,
        public ?SeatChangeRequest $scheduledReduction,
        public array $errors,
    ) {}

    public function monthlyFee(): int
    {
        return Pricing::monthlyFee($this->seatPrice, $this->seats);
    }

    public function setupFee(): int
    {
        return Pricing::setupFee($this->seatPrice, $this->setupSeats, Pricing::MonthsPerTerm);
    }

    /**
     * The expiring term's monthly charge for the same seats, for comparison.
     */
    public function previousMonthlyFee(): int
    {
        return $this->previousTerm->monthlyFee($this->seats);
    }

    /**
     * Whether the price per seat differs from the expiring term's snapshot.
     */
    public function pricingChanged(): bool
    {
        return $this->seatPrice !== $this->previousTerm->seat_price;
    }

    public function isConfirmable(): bool
    {
        return $this->errors === [];
    }

    public function format(int $minor): string
    {
        return $this->previousTerm->format($minor);
    }
}
