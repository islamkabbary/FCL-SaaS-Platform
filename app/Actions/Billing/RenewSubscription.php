<?php

namespace App\Actions\Billing;

use App\Billing\ActiveUsers;
use App\Billing\Money;
use App\Billing\Pricing;
use App\Billing\RenewalQuote;
use App\Enums\ChargeType;
use App\Enums\PlatformAuditAction;
use App\Enums\SeatRequestStatus;
use App\Enums\SubscriptionTermKind;
use App\Exceptions\InvalidSubscriptionChange;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SeatChangeRequest;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTerm;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Manually renews a subscription for another annual term and charges it.
 *
 * - Renewal opens "renewal_window_days" before the latest term ends.
 * - Confirmed on time, the new term starts on the previous renewal date (no gap, no
 *   overlap). Confirmed after the subscription expired, it starts on the day of
 *   confirmation: the lapsed period is not billed.
 * - The seat count defaults to an approved reduction, otherwise the current seats. It can
 *   never be lower than the tenant's active users: no user is ever deactivated to fit.
 * - The new term snapshots the chosen plan's current prices. When they differ from the
 *   expiring term, the admin must confirm the change explicitly.
 * - All previous terms and charges are kept unchanged.
 */
class RenewSubscription
{
    public function __construct(
        protected ActiveUsers $activeUsers,
        protected CreateCharge $createCharge,
    ) {}

    /**
     * @throws InvalidSubscriptionChange When the tenant has no subscription.
     */
    public function quote(Tenant $tenant, ?SubscriptionPlan $plan = null, ?int $seats = null): RenewalQuote
    {
        $subscription = TenantSubscription::query()->where('tenant_id', $tenant->getTenantKey())->first()
            ?? throw new InvalidSubscriptionChange(__('This tenant has no subscription to renew.'));

        $previous = $subscription->latestTerm() ?? throw new InvalidSubscriptionChange(__('This tenant has no subscription to renew.'));
        $plan ??= $subscription->plan;
        $today = Pricing::day(CarbonImmutable::today());
        $previousEnd = Pricing::day($previous->ends_on);

        $reduction = SeatChangeRequest::query()
            ->where('tenant_subscription_id', $subscription->id)
            ->scheduledReduction()
            ->latest('reviewed_at')
            ->first();

        $seats ??= $reduction->seats ?? $previous->seats;
        $startsOn = $today->greaterThan($previousEnd) ? $today : $previousEnd;
        $activeUsers = $tenant->isReady() ? $this->activeUsers->count($tenant) : 0;
        $opensOn = $previousEnd->subDays((int) config('billing.renewal_window_days'));
        $errors = [];

        if ($today->lessThan($opensOn)) {
            $errors[] = __('Renewal opens on :date (:days days before the current term ends).', [
                'date' => $opensOn->toDateString(),
                'days' => config('billing.renewal_window_days'),
            ]);
        }

        if (! $plan->is_active) {
            $errors[] = __('The plan ":plan" is archived. Choose an active plan for the new term.', ['plan' => $plan->name]);
        }

        if ($plan->currency !== $subscription->currency) {
            $errors[] = __('The plan is priced in :plan, but the subscription is in :subscription. Currencies cannot be mixed.', [
                'plan' => $plan->currency,
                'subscription' => $subscription->currency,
            ]);
        }

        if ($seats < max(1, $activeUsers)) {
            $errors[] = __(':users users are active, more than the :seats seats proposed for the new term. Ask the tenant to deactivate users, or renew with at least :users seats.', [
                'users' => $activeUsers,
                'seats' => $seats,
            ]);
        }

        if ($seats > config('billing.max_seats')) {
            $errors[] = __('At most :max seats can be purchased.', ['max' => config('billing.max_seats')]);
        }

        return new RenewalQuote($previous, $plan, $seats, $startsOn, Pricing::annualTermEnd($startsOn), $activeUsers, $reduction, $errors);
    }

    /**
     * $expectedTotal is the annual total shown in the preview the admin confirmed. When the
     * plan's prices changed since then, nothing is created: charges cannot be edited, so an
     * amount nobody reviewed must never be charged.
     *
     * $expectedPreviousSeats and $expectedReductionId (0 for none) are the current term's
     * seats and the approved reduction shown in that preview. When either changed since
     * (seats bought, a reduction approved or superseded), nothing is created: confirming the
     * old form would silently drop seats just paid for, or ignore the new reduction.
     *
     * @throws InvalidSubscriptionChange
     */
    public function handle(PlatformAdmin $admin, Tenant $tenant, SubscriptionPlan $plan, int $seats, bool $acceptPricingChange, ?int $expectedTotal = null, ?int $expectedPreviousSeats = null, ?int $expectedReductionId = null): SubscriptionTerm
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($admin, $tenant, $plan, $seats, $acceptPricingChange, $expectedTotal, $expectedPreviousSeats, $expectedReductionId): SubscriptionTerm {
            // Lock the subscription so two renewals cannot both pass the checks below.
            $subscription = TenantSubscription::query()->where('tenant_id', $tenant->getTenantKey())->lockForUpdate()->first()
                ?? throw new InvalidSubscriptionChange(__('This tenant has no subscription to renew.'));

            $quote = $this->quote($tenant, SubscriptionPlan::query()->whereKey($plan->id)->firstOrFail(), $seats);

            if (! $quote->isConfirmable()) {
                throw new InvalidSubscriptionChange($quote->errors[0]);
            }

            if ($expectedPreviousSeats !== null && $quote->previousTerm->seats !== $expectedPreviousSeats) {
                throw new InvalidSubscriptionChange(__('The current term changed since the preview: it now has :seats seats. Review the renewal again.', [
                    'seats' => $quote->previousTerm->seats,
                ]));
            }

            if ($expectedReductionId !== null && ($quote->scheduledReduction->id ?? 0) !== $expectedReductionId) {
                throw new InvalidSubscriptionChange(__('An approved seat reduction changed since the preview. Review the renewal again.'));
            }

            if ($quote->pricingChanged() && ! $acceptPricingChange) {
                throw new InvalidSubscriptionChange(__('The plan or its prices differ from the current term. Review the new prices and confirm the change.'));
            }

            if ($expectedTotal !== null && $quote->annualTotal() !== $expectedTotal) {
                throw new InvalidSubscriptionChange(__('The plan\'s prices changed after the preview: the annual total is now :total. Review it and confirm again.', [
                    'total' => $quote->plan->format($quote->annualTotal()),
                ]));
            }

            $previous = $quote->previousTerm;

            $term = SubscriptionTerm::query()->create([
                'tenant_subscription_id' => $subscription->id,
                'tenant_id' => $tenant->getTenantKey(),
                'sequence' => $previous->sequence + 1,
                'kind' => SubscriptionTermKind::Renewal,
                'subscription_plan_id' => $quote->plan->id,
                'plan_name' => $quote->plan->name,
                'currency' => $quote->plan->currency,
                'annual_base_fee' => $quote->plan->annual_base_fee,
                'annual_seat_price' => $quote->plan->annual_seat_price,
                'initial_seats' => $quote->seats,
                'seats' => $quote->seats,
                'starts_on' => $quote->startsOn,
                'ends_on' => $quote->endsOn,
                'created_by' => $admin->id,
            ]);

            $subscription->forceFill(['subscription_plan_id' => $quote->plan->id])->save();

            // An approved reduction is consumed by this renewal: applied when the new term has
            // exactly its seats, otherwise superseded by the seat count the admin confirmed.
            $reduction = $quote->scheduledReduction;
            $reductionApplied = $reduction !== null && $reduction->seats === $term->seats;

            if ($reduction !== null) {
                SeatChangeRequest::query()->whereKey($reduction->id)->scheduledReduction()->update($reductionApplied
                    ? ['status' => SeatRequestStatus::Applied, 'applied_to_term_id' => $term->id]
                    : ['status' => SeatRequestStatus::Superseded]);
            }

            PlatformAuditLog::record($admin, $tenant, PlatformAuditAction::SubscriptionRenewed, [
                'plan' => $previous->plan_name,
                'annual_base_fee' => $previous->annual_base_fee,
                'annual_seat_price' => $previous->annual_seat_price,
                'seats' => $previous->seats,
                'ends_on' => $previous->ends_on->toDateString(),
            ], [
                'plan' => $term->plan_name,
                'annual_base_fee' => $term->annual_base_fee,
                'annual_seat_price' => $term->annual_seat_price,
                'seats' => $term->seats,
                'starts_on' => $term->starts_on->toDateString(),
                'ends_on' => $term->ends_on->toDateString(),
                'annual_total' => Money::format($term->annualTotal(), $term->currency),
            ], match (true) {
                $reduction === null => null,
                $reductionApplied => "Applied approved seat reduction #{$reduction->id}",
                default => "Approved seat reduction #{$reduction->id} to {$reduction->seats} seats superseded: renewed with {$term->seats} seats",
            });

            $this->createCharge->handle(
                $admin, $tenant, $subscription, $term, ChargeType::Renewal,
                "term:{$term->id}",
                __('Annual renewal :start – :end', ['start' => $term->starts_on->toDateString(), 'end' => $term->lastDay()->toDateString()]),
                CreateCharge::annualLines($term),
            );

            return $term;
        });
    }
}
