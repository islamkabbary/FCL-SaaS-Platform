<?php

namespace App\Actions\Billing;

use App\Billing\Pricing;
use App\Enums\ChargeType;
use App\Enums\PlatformAuditAction;
use App\Models\BillingCharge;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SubscriptionTerm;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Creates a charge with its lines and audits it. Must be called inside the central
 * transaction of the business operation that produced it.
 *
 * The idempotency key names that operation (e.g. "setup:term:12", "monthly:12:3"); its
 * unique index makes it impossible to charge the same event twice.
 *
 * A null admin means the charge was issued by the system (the monthly scheduler).
 */
class CreateCharge
{
    /**
     * @param  list<array{description: string, quantity: int, unit_amount: int, amount: int, calculation?: array<string, mixed>}>  $lines
     * @param  array{index: int, starts_on: CarbonImmutable, ends_on: CarbonImmutable}|null  $period  The billed month of a monthly charge.
     */
    public function handle(
        ?PlatformAdmin $admin,
        Tenant $tenant,
        TenantSubscription $subscription,
        ?SubscriptionTerm $term,
        ChargeType $type,
        string $idempotencyKey,
        string $description,
        array $lines,
        int $discountBasisPoints = 0,
        ?array $period = null,
    ): BillingCharge {
        $subtotal = 0;

        foreach ($lines as $line) {
            if ($line['amount'] < 0 || $line['quantity'] < 0 || $line['unit_amount'] < 0) {
                throw new InvalidArgumentException('Charge lines cannot be negative.');
            }

            $subtotal += $line['amount'];
        }

        $discount = Pricing::discount($subtotal, $discountBasisPoints);

        $charge = BillingCharge::query()->create([
            'tenant_id' => $tenant->getTenantKey(),
            'tenant_subscription_id' => $subscription->id,
            'subscription_term_id' => $term?->id,
            'type' => $type,
            'currency' => $subscription->currency,
            'subtotal' => $subtotal,
            'discount_basis_points' => $discountBasisPoints,
            'discount_amount' => $discount,
            'amount' => $subtotal - $discount,
            'description' => $description,
            'period_index' => $period['index'] ?? null,
            'period_starts_on' => $period['starts_on'] ?? null,
            'period_ends_on' => $period['ends_on'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'issued_on' => CarbonImmutable::today(),
            'created_by' => $admin?->id,
        ]);

        foreach ($lines as $line) {
            $charge->lines()->create([
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_amount' => $line['unit_amount'],
                'amount' => $line['amount'],
                'calculation' => $line['calculation'] ?? null,
            ]);
        }

        $values = [
            'charge_id' => $charge->id,
            'type' => $type->value,
            'subtotal' => $subtotal,
            'discount_basis_points' => $discountBasisPoints,
            'amount' => $charge->amount,
            'currency' => $charge->currency,
        ];

        $admin !== null
            ? PlatformAuditLog::record($admin, $tenant, PlatformAuditAction::ChargeCreated, newValues: $values)
            : PlatformAuditLog::recordSystem($tenant, PlatformAuditAction::ChargeCreated, $values);

        return $charge;
    }

    /**
     * The line of a setup fee: seat price × months for each seat.
     *
     * @return list<array{description: string, quantity: int, unit_amount: int, amount: int, calculation: array<string, mixed>}>
     */
    public static function setupLines(SubscriptionTerm $term, int $seats, int $months): array
    {
        return [[
            'description' => __('Setup fee, :seats seats × :months months (:from – :to)', [
                'seats' => $seats,
                'months' => $months,
                'from' => $term->starts_on->toDateString(),
                'to' => $term->lastDay()->toDateString(),
            ]),
            'quantity' => $seats,
            'unit_amount' => Pricing::setupFee($term->seat_price, 1, $months),
            'amount' => Pricing::setupFee($term->seat_price, $seats, $months),
            'calculation' => ['term_id' => $term->id, 'seat_price' => $term->seat_price, 'seats' => $seats, 'months' => $months],
        ]];
    }

    /**
     * The line of a monthly charge: the term's seats at its seat price.
     *
     * @return list<array{description: string, quantity: int, unit_amount: int, amount: int, calculation: array<string, mixed>}>
     */
    public static function monthlyLines(SubscriptionTerm $term, CarbonImmutable $startsOn, CarbonImmutable $endsOn): array
    {
        return [[
            'description' => __('Monthly subscription, :seats seats (:from – :to)', [
                'seats' => $term->seats,
                'from' => $startsOn->toDateString(),
                'to' => $endsOn->subDay()->toDateString(),
            ]),
            'quantity' => $term->seats,
            'unit_amount' => $term->seat_price,
            'amount' => $term->monthlyFee(),
            'calculation' => ['term_id' => $term->id, 'seat_price' => $term->seat_price, 'seats' => $term->seats],
        ]];
    }
}
