<?php

namespace App\Billing;

use App\Models\SeatChangeRequest;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTerm;
use Carbon\CarbonImmutable;

/**
 * Everything a Platform Admin needs to review before confirming a renewal.
 */
final readonly class RenewalQuote
{
    /**
     * @param  list<string>  $errors  Reasons the renewal cannot be confirmed as proposed.
     */
    public function __construct(
        public SubscriptionTerm $previousTerm,
        public SubscriptionPlan $plan,
        public int $seats,
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
        public int $activeUsers,
        public ?SeatChangeRequest $scheduledReduction,
        public array $errors,
    ) {}

    public function annualTotal(): int
    {
        return $this->plan->annualTotal($this->seats);
    }

    /**
     * The expiring term's total for the same seats, for comparison.
     */
    public function previousPricesTotal(): int
    {
        return $this->previousTerm->annualTotal($this->seats);
    }

    /**
     * Whether the plan or its prices differ from the expiring term's snapshot.
     */
    public function pricingChanged(): bool
    {
        return $this->plan->id !== $this->previousTerm->subscription_plan_id
            || $this->plan->annual_base_fee !== $this->previousTerm->annual_base_fee
            || $this->plan->annual_seat_price !== $this->previousTerm->annual_seat_price;
    }

    public function isConfirmable(): bool
    {
        return $this->errors === [];
    }
}
