<?php

namespace App\Billing;

use App\Exceptions\InvalidSubscriptionChange;
use App\Models\SubscriptionTerm;
use App\Models\TenantSubscription;
use Carbon\CarbonImmutable;

/**
 * The setup fee for additional seats approved on a given day: seat price × the current
 * term's remaining months (the month already started counts in full) × the new seats.
 *
 * A seat pays its setup fee once, so only the seats above the subscription's
 * "setup_paid_seats" are charged; seats bought back after a reduction cost nothing now.
 * From the next monthly period on, the seats are included in the monthly charge.
 *
 * The tenant's estimate and the approval use this same calculation, so the tenant is
 * never shown less than they would be charged.
 */
final class SeatIncreaseQuote
{
    /**
     * @return list<array{description: string, quantity: int, unit_amount: int, amount: int, calculation: array<string, mixed>}>
     *
     * @throws InvalidSubscriptionChange When the subscription has no term on that day.
     */
    public static function lines(TenantSubscription $subscription, int $seats, CarbonImmutable $on): array
    {
        $on = Pricing::day($on);
        $term = $subscription->termOn($on)
            ?? throw new InvalidSubscriptionChange(__('The subscription has no active term today, so additional seats cannot be charged. Reject the request instead.'));

        $mostSeats = max([$term->seats, ...array_map(fn (SubscriptionTerm $future): int => $future->seats, self::futureTerms($subscription, $on))]);
        $chargeable = min($seats, $subscription->seatsNeedingSetup($mostSeats + $seats));

        if ($chargeable === 0) {
            return [];
        }

        $proration = Pricing::prorate($chargeable, $term->seat_price, $term->starts_on, $on);

        return [[
            'description' => __('Setup fee, :seats additional seats × :months of :total months (:from – :to)', [
                'seats' => $chargeable,
                'months' => $proration->remainingMonths,
                'total' => $proration->totalMonths,
                'from' => Pricing::periodBounds($term->starts_on, Pricing::periodIndex($term->starts_on, $on))[0]->toDateString(),
                'to' => $term->lastDay()->toDateString(),
            ]),
            'quantity' => $chargeable,
            'unit_amount' => Pricing::setupFee($term->seat_price, 1, $proration->remainingMonths),
            'amount' => $proration->amount,
            'calculation' => [
                'term_id' => $term->id,
                'seat_price' => $term->seat_price,
                'requested_seats' => $seats,
                'seats' => $chargeable,
                'setup_paid_seats' => $subscription->setup_paid_seats,
                'calculated_on' => $on->toDateString(),
                'term_starts_on' => $term->starts_on->toDateString(),
                'remaining_months' => $proration->remainingMonths,
                'total_months' => $proration->totalMonths,
            ],
        ]];
    }

    /**
     * @param  list<array{amount: int}>  $lines
     */
    public static function total(array $lines): int
    {
        return array_sum(array_column($lines, 'amount'));
    }

    /**
     * Terms confirmed by an early renewal that start after the given day.
     *
     * @return list<SubscriptionTerm>
     */
    public static function futureTerms(TenantSubscription $subscription, CarbonImmutable $on): array
    {
        return array_values(SubscriptionTerm::query()
            ->where('tenant_subscription_id', $subscription->id)
            ->whereDate('starts_on', '>', $on->toDateString())
            ->orderBy('sequence')
            ->get()
            ->all());
    }
}
