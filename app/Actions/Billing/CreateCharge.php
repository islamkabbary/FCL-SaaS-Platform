<?php

namespace App\Actions\Billing;

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
 * The idempotency key names that operation (e.g. "term:12", "seat-request:7"); its
 * unique index makes it impossible to charge the same event twice.
 */
class CreateCharge
{
    /**
     * @param  list<array{description: string, quantity: int, unit_amount: int, amount: int, calculation?: array<string, mixed>}>  $lines
     */
    public function handle(
        PlatformAdmin $admin,
        Tenant $tenant,
        TenantSubscription $subscription,
        ?SubscriptionTerm $term,
        ChargeType $type,
        string $idempotencyKey,
        string $description,
        array $lines,
    ): BillingCharge {
        $amount = 0;

        foreach ($lines as $line) {
            if ($line['amount'] < 0 || $line['quantity'] < 0 || $line['unit_amount'] < 0) {
                throw new InvalidArgumentException('Charge lines cannot be negative.');
            }

            $amount += $line['amount'];
        }

        $charge = BillingCharge::query()->create([
            'tenant_id' => $tenant->getTenantKey(),
            'tenant_subscription_id' => $subscription->id,
            'subscription_term_id' => $term?->id,
            'type' => $type,
            'currency' => $subscription->currency,
            'amount' => $amount,
            'description' => $description,
            'idempotency_key' => $idempotencyKey,
            'issued_on' => CarbonImmutable::today(),
            'created_by' => $admin->id,
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

        PlatformAuditLog::record($admin, $tenant, PlatformAuditAction::ChargeCreated, newValues: [
            'charge_id' => $charge->id,
            'type' => $type->value,
            'amount' => $amount,
            'currency' => $charge->currency,
        ]);

        return $charge;
    }

    /**
     * Lines for an annual term: the base fee and the seats, at the term's snapshot prices.
     *
     * @return list<array{description: string, quantity: int, unit_amount: int, amount: int}>
     */
    public static function annualLines(SubscriptionTerm $term): array
    {
        $period = $term->starts_on->toDateString().' – '.$term->lastDay()->toDateString();

        return [
            [
                'description' => __('Annual base fee, :plan (:period)', ['plan' => $term->plan_name, 'period' => $period]),
                'quantity' => 1,
                'unit_amount' => $term->annual_base_fee,
                'amount' => $term->annual_base_fee,
            ],
            [
                'description' => __('Annual seats, :plan (:period)', ['plan' => $term->plan_name, 'period' => $period]),
                'quantity' => $term->initial_seats,
                'unit_amount' => $term->annual_seat_price,
                'amount' => $term->annualTotal() - $term->annual_base_fee,
            ],
        ];
    }
}
