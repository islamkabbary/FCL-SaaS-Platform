<?php

namespace App\Billing;

use App\Exceptions\InvalidSubscriptionChange;
use App\Models\SubscriptionTerm;
use App\Models\TenantSubscription;
use Carbon\CarbonImmutable;

/**
 * The charge for additional seats approved on a given day: the rest of the current term,
 * prorated, plus every later term already confirmed by an early renewal, in full at that
 * term's snapshot price. The tenant's estimate and the approval use this same calculation,
 * so the tenant is never shown less than they would be charged.
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

        $proration = Pricing::prorate($seats, $term->annual_seat_price, $term->starts_on, $term->ends_on, $on);

        $lines = [[
            'description' => __(':seats additional seats, :from – :to (:remaining of :total days)', [
                'seats' => $seats,
                'from' => $on->toDateString(),
                'to' => $term->lastDay()->toDateString(),
                'remaining' => $proration->remainingDays,
                'total' => $proration->totalDays,
            ]),
            'quantity' => $seats,
            'unit_amount' => $term->annual_seat_price,
            'amount' => $proration->amount,
            'calculation' => [
                'term_id' => $term->id,
                'annual_seat_price' => $term->annual_seat_price,
                'seats' => $seats,
                'calculated_on' => $on->toDateString(),
                'term_starts_on' => $term->starts_on->toDateString(),
                'term_ends_on' => $term->ends_on->toDateString(),
                'remaining_days' => $proration->remainingDays,
                'total_days' => $proration->totalDays,
                'rounding' => 'half_up_to_minor_unit',
            ],
        ]];

        foreach (self::futureTerms($subscription, $on) as $future) {
            $lines[] = [
                'description' => __(':seats additional seats, renewed term :from – :to', [
                    'seats' => $seats,
                    'from' => $future->starts_on->toDateString(),
                    'to' => $future->lastDay()->toDateString(),
                ]),
                'quantity' => $seats,
                'unit_amount' => $future->annual_seat_price,
                'amount' => $future->annual_seat_price * $seats,
                'calculation' => ['term_id' => $future->id, 'annual_seat_price' => $future->annual_seat_price, 'seats' => $seats],
            ];
        }

        return $lines;
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
