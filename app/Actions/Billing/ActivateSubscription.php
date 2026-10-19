<?php

namespace App\Actions\Billing;

use App\Billing\ActiveUsers;
use App\Billing\Money;
use App\Billing\Pricing;
use App\Enums\ChargeType;
use App\Enums\PlatformAuditAction;
use App\Enums\SubscriptionTermKind;
use App\Exceptions\InvalidSubscriptionChange;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTerm;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Manually activates a tenant's first annual subscription: creates the subscription,
 * its first term (with a pricing snapshot of the plan) and the initial annual charge.
 *
 * Also used to transition a legacy tenant: from then on it requires a subscription.
 * Provisioning status and manual suspension are never touched.
 */
class ActivateSubscription
{
    public function __construct(
        protected ActiveUsers $activeUsers,
        protected CreateCharge $createCharge,
    ) {}

    /**
     * Validate the activation and describe its effect without saving anything.
     *
     * @return array{starts_on: CarbonImmutable, ends_on: CarbonImmutable, active_users: int, annual_total: int, errors: list<string>}
     */
    public function quote(Tenant $tenant, SubscriptionPlan $plan, int $seats, CarbonImmutable $startsOn): array
    {
        $startsOn = Pricing::day($startsOn);
        $endsOn = Pricing::annualTermEnd($startsOn);
        $today = Pricing::day(CarbonImmutable::today());
        $activeUsers = $tenant->isReady() ? $this->activeUsers->count($tenant) : 0;
        $errors = [];

        if (! $tenant->isReady()) {
            $errors[] = __('Only a ready (provisioned) tenant can be subscribed.');
        }

        if (TenantSubscription::query()->where('tenant_id', $tenant->getTenantKey())->exists()) {
            $errors[] = __('This tenant already has a subscription. Use renewal instead.');
        }

        if (! $plan->is_active) {
            $errors[] = __('The plan ":plan" is archived.', ['plan' => $plan->name]);
        }

        if ($startsOn->greaterThan($today)) {
            $errors[] = __('The start date cannot be in the future.');
        }

        if ($endsOn->lessThanOrEqualTo($today)) {
            $errors[] = __('A term starting on this date would already have ended.');
        }

        if ($seats < max(1, $activeUsers)) {
            $errors[] = __('At least :count seats are needed: the tenant has :count active users.', ['count' => max(1, $activeUsers)]);
        }

        if ($seats > config('billing.max_seats')) {
            $errors[] = __('At most :max seats can be purchased.', ['max' => config('billing.max_seats')]);
        }

        return [
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'active_users' => $activeUsers,
            'annual_total' => $plan->annualTotal(max(0, $seats)),
            'errors' => $errors,
        ];
    }

    /**
     * @throws InvalidSubscriptionChange
     */
    public function handle(PlatformAdmin $admin, Tenant $tenant, SubscriptionPlan $plan, int $seats, CarbonImmutable $startsOn): TenantSubscription
    {
        // The active-user count comes from the tenant database, outside the central transaction.
        $quote = $this->quote($tenant, $plan, $seats, $startsOn);

        if ($quote['errors'] !== []) {
            throw new InvalidSubscriptionChange($quote['errors'][0]);
        }

        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($admin, $tenant, $plan, $seats, $quote): TenantSubscription {
            // Serializes activations of the same tenant; the unique tenant_id is the final guard.
            $tenant = Tenant::query()->whereKey($tenant->getTenantKey())->lockForUpdate()->firstOrFail();
            $plan = SubscriptionPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if (TenantSubscription::query()->where('tenant_id', $tenant->getTenantKey())->exists()) {
                throw new InvalidSubscriptionChange(__('This tenant already has a subscription. Use renewal instead.'));
            }

            if (! $plan->is_active) {
                throw new InvalidSubscriptionChange(__('The plan ":plan" is archived.', ['plan' => $plan->name]));
            }

            $subscription = TenantSubscription::query()->create([
                'tenant_id' => $tenant->getTenantKey(),
                'subscription_plan_id' => $plan->id,
                'currency' => $plan->currency,
                'activated_at' => now(),
                'activated_by' => $admin->id,
            ]);

            $term = SubscriptionTerm::query()->create([
                'tenant_subscription_id' => $subscription->id,
                'tenant_id' => $tenant->getTenantKey(),
                'sequence' => 1,
                'kind' => SubscriptionTermKind::Initial,
                'subscription_plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'currency' => $plan->currency,
                'annual_base_fee' => $plan->annual_base_fee,
                'annual_seat_price' => $plan->annual_seat_price,
                'initial_seats' => $seats,
                'seats' => $seats,
                'starts_on' => $quote['starts_on'],
                'ends_on' => $quote['ends_on'],
                'created_by' => $admin->id,
            ]);

            // From now on the tenant is governed by its subscription (ends legacy treatment).
            $tenant->forceFill(['subscription_required' => true])->save();

            PlatformAuditLog::record($admin, $tenant, PlatformAuditAction::SubscriptionActivated, newValues: [
                'plan' => $plan->name,
                'currency' => $plan->currency,
                'annual_base_fee' => $plan->annual_base_fee,
                'annual_seat_price' => $plan->annual_seat_price,
                'seats' => $seats,
                'starts_on' => $term->starts_on->toDateString(),
                'ends_on' => $term->ends_on->toDateString(),
                'annual_total' => Money::format($term->annualTotal(), $term->currency),
            ]);

            $this->createCharge->handle(
                $admin, $tenant, $subscription, $term, ChargeType::Initial,
                "term:{$term->id}",
                __('Annual subscription :start – :end', ['start' => $term->starts_on->toDateString(), 'end' => $term->lastDay()->toDateString()]),
                CreateCharge::annualLines($term),
            );

            return $subscription;
        });
    }
}
