<?php

namespace App\Actions\Billing;

use App\Billing\ActiveUsers;
use App\Billing\Money;
use App\Billing\Pricing;
use App\Enums\ChargeType;
use App\Enums\PlatformAuditAction;
use App\Enums\SubscriptionTermKind;
use App\Exceptions\InvalidSubscriptionChange;
use App\Models\BillingSetting;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SubscriptionTerm;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Manually activates a tenant's subscription: creates the subscription and its first
 * annual term (with a snapshot of the current price per seat), the setup fee for all its
 * seats (seat price × 12 × seats, optionally discounted) and the first monthly charge.
 *
 * Also used to transition a legacy tenant: from then on it requires a subscription.
 * Provisioning status and manual suspension are never touched.
 */
class ActivateSubscription
{
    public function __construct(
        protected ActiveUsers $activeUsers,
        protected CreateCharge $createCharge,
        protected IssueMonthlyCharges $issueMonthlyCharges,
    ) {}

    /**
     * Validate the activation and describe its effect without saving anything.
     *
     * @return array{starts_on: CarbonImmutable, ends_on: CarbonImmutable, active_users: int, currency: ?string, seat_price: int, setup_fee: int, discount: int, monthly_fee: int, errors: list<string>}
     */
    public function quote(Tenant $tenant, int $seats, CarbonImmutable $startsOn, int $discountBasisPoints = 0): array
    {
        $setting = BillingSetting::current();
        $startsOn = Pricing::day($startsOn);
        $endsOn = Pricing::annualTermEnd($startsOn);
        $today = Pricing::day(CarbonImmutable::today());
        $activeUsers = $tenant->isReady() ? $this->activeUsers->count($tenant) : 0;
        $errors = [];

        if ($setting === null) {
            $errors[] = __('Set the price per user in the billing settings first.');
        }

        if (! $tenant->isReady()) {
            $errors[] = __('Only a ready (provisioned) tenant can be subscribed.');
        }

        if (TenantSubscription::query()->where('tenant_id', $tenant->getTenantKey())->exists()) {
            $errors[] = __('This tenant already has a subscription. Use renewal instead.');
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

        if ($discountBasisPoints < 0 || $discountBasisPoints > Pricing::MaxDiscountBasisPoints) {
            $errors[] = __('The discount must be between 0% and 100%.');
        }

        $seatPrice = $setting->seat_price ?? 0;
        $setupFee = Pricing::setupFee($seatPrice, max(0, min($seats, (int) config('billing.max_seats'))), Pricing::MonthsPerTerm);

        return [
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'active_users' => $activeUsers,
            'currency' => $setting?->currency,
            'seat_price' => $seatPrice,
            'setup_fee' => $setupFee,
            'discount' => Pricing::discount($setupFee, max(0, min($discountBasisPoints, Pricing::MaxDiscountBasisPoints))),
            'monthly_fee' => Pricing::monthlyFee($seatPrice, max(0, $seats)),
            'errors' => $errors,
        ];
    }

    /**
     * $expectedTotal is the setup fee (before discount) shown in the preview the admin
     * confirmed. When the price per seat changed since then, nothing is created: an amount
     * nobody reviewed must never be charged.
     *
     * @throws InvalidSubscriptionChange
     */
    public function handle(PlatformAdmin $admin, Tenant $tenant, int $seats, CarbonImmutable $startsOn, ?int $expectedTotal = null, int $discountBasisPoints = 0): TenantSubscription
    {
        // The active-user count comes from the tenant database, outside the central transaction.
        $quote = $this->quote($tenant, $seats, $startsOn, $discountBasisPoints);

        if ($quote['errors'] !== []) {
            throw new InvalidSubscriptionChange($quote['errors'][0]);
        }

        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($admin, $tenant, $seats, $quote, $expectedTotal, $discountBasisPoints): TenantSubscription {
            // Serializes activations of the same tenant; the unique tenant_id is the final guard.
            $tenant = Tenant::query()->whereKey($tenant->getTenantKey())->lockForUpdate()->firstOrFail();
            $setting = BillingSetting::query()->whereKey(BillingSetting::current()?->id)->lockForUpdate()->first()
                ?? throw new InvalidSubscriptionChange(__('Set the price per user in the billing settings first.'));

            if (TenantSubscription::query()->where('tenant_id', $tenant->getTenantKey())->exists()) {
                throw new InvalidSubscriptionChange(__('This tenant already has a subscription. Use renewal instead.'));
            }

            $setupFee = Pricing::setupFee($setting->seat_price, $seats, Pricing::MonthsPerTerm);

            if ($expectedTotal !== null && $setupFee !== $expectedTotal) {
                throw new InvalidSubscriptionChange(__('The price per user changed after the preview: the setup fee is now :total. Review it and confirm again.', [
                    'total' => $setting->format($setupFee),
                ]));
            }

            $subscription = TenantSubscription::query()->create([
                'tenant_id' => $tenant->getTenantKey(),
                'currency' => $setting->currency,
                'setup_paid_seats' => $seats,
                'activated_at' => now(),
                'activated_by' => $admin->id,
            ]);

            $term = SubscriptionTerm::query()->create([
                'tenant_subscription_id' => $subscription->id,
                'tenant_id' => $tenant->getTenantKey(),
                'sequence' => 1,
                'kind' => SubscriptionTermKind::Initial,
                'currency' => $setting->currency,
                'seat_price' => $setting->seat_price,
                'initial_seats' => $seats,
                'seats' => $seats,
                'starts_on' => $quote['starts_on'],
                'ends_on' => $quote['ends_on'],
                'created_by' => $admin->id,
            ]);

            // From now on the tenant is governed by its subscription (ends legacy treatment).
            $tenant->forceFill(['subscription_required' => true])->save();

            PlatformAuditLog::record($admin, $tenant, PlatformAuditAction::SubscriptionActivated, newValues: [
                'currency' => $term->currency,
                'seat_price' => $term->seat_price,
                'seats' => $seats,
                'starts_on' => $term->starts_on->toDateString(),
                'ends_on' => $term->ends_on->toDateString(),
                'setup_fee' => Money::format($setupFee, $term->currency),
                'monthly_fee' => Money::format($term->monthlyFee(), $term->currency),
            ]);

            $this->createCharge->handle(
                $admin, $tenant, $subscription, $term, ChargeType::Setup,
                "setup:term:{$term->id}",
                __('Setup fee :start – :end', ['start' => $term->starts_on->toDateString(), 'end' => $term->lastDay()->toDateString()]),
                CreateCharge::setupLines($term, $seats, Pricing::MonthsPerTerm),
                $discountBasisPoints,
            );

            $this->issueMonthlyCharges->forTerm($term);

            return $subscription;
        });
    }
}
