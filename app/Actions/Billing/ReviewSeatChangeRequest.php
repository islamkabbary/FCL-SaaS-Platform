<?php

namespace App\Actions\Billing;

use App\Billing\Money;
use App\Billing\Pricing;
use App\Enums\ChargeType;
use App\Enums\PlatformAuditAction;
use App\Enums\SeatRequestStatus;
use App\Enums\SeatRequestType;
use App\Exceptions\InvalidSubscriptionChange;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SeatChangeRequest;
use App\Models\SubscriptionTerm;
use App\Models\TenantSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A Platform Admin approves or rejects a tenant's seat request.
 *
 * Approving an increase adds the seats to the current term (sharing its renewal date)
 * and charges them prorated for the rest of that term, computed on the approval day.
 * If the next term was already confirmed by an early renewal, the seats are added to it
 * too and charged for that full term at its snapshot price.
 *
 * Approving a reduction changes nothing now; it is applied by the next renewal, unless an
 * increase is approved before then, which supersedes it.
 *
 * The request row is locked and must still be pending, and the charge's idempotency key
 * is unique per request, so a request can never be approved or charged twice.
 */
class ReviewSeatChangeRequest
{
    public function __construct(protected CreateCharge $createCharge) {}

    /**
     * The lines an approval on the given day would charge (empty for reductions).
     *
     * @return list<array{description: string, quantity: int, unit_amount: int, amount: int, calculation?: array<string, mixed>}>
     *
     * @throws InvalidSubscriptionChange
     */
    public function quote(SeatChangeRequest $request, ?CarbonImmutable $on = null): array
    {
        if ($request->type !== SeatRequestType::Increase) {
            return [];
        }

        $on = Pricing::day($on ?? CarbonImmutable::today());
        $subscription = TenantSubscription::query()->findOrFail($request->tenant_subscription_id);
        $term = $subscription->termOn($on)
            ?? throw new InvalidSubscriptionChange(__('The subscription has no active term today, so additional seats cannot be charged. Reject the request instead.'));

        $proration = Pricing::prorate($request->seats, $term->annual_seat_price, $term->starts_on, $term->ends_on, $on);

        $lines = [[
            'description' => __(':seats additional seats, :from – :to (:remaining of :total days)', [
                'seats' => $request->seats,
                'from' => $on->toDateString(),
                'to' => $term->lastDay()->toDateString(),
                'remaining' => $proration->remainingDays,
                'total' => $proration->totalDays,
            ]),
            'quantity' => $request->seats,
            'unit_amount' => $term->annual_seat_price,
            'amount' => $proration->amount,
            'calculation' => [
                'term_id' => $term->id,
                'annual_seat_price' => $term->annual_seat_price,
                'seats' => $request->seats,
                'calculated_on' => $on->toDateString(),
                'term_starts_on' => $term->starts_on->toDateString(),
                'term_ends_on' => $term->ends_on->toDateString(),
                'remaining_days' => $proration->remainingDays,
                'total_days' => $proration->totalDays,
                'rounding' => 'half_up_to_minor_unit',
            ],
        ]];

        foreach ($this->futureTerms($subscription, $on) as $future) {
            $lines[] = [
                'description' => __(':seats additional seats, renewed term :from – :to', [
                    'seats' => $request->seats,
                    'from' => $future->starts_on->toDateString(),
                    'to' => $future->lastDay()->toDateString(),
                ]),
                'quantity' => $request->seats,
                'unit_amount' => $future->annual_seat_price,
                'amount' => $future->annual_seat_price * $request->seats,
                'calculation' => ['term_id' => $future->id, 'annual_seat_price' => $future->annual_seat_price, 'seats' => $request->seats],
            ];
        }

        return $lines;
    }

    /**
     * @throws InvalidSubscriptionChange
     */
    public function approve(PlatformAdmin $admin, SeatChangeRequest $request, ?string $note = null): SeatChangeRequest
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($admin, $request, $note): SeatChangeRequest {
            // Lock the subscription before the request, in the same order as a renewal (which
            // locks the subscription and then updates requests), so the two cannot deadlock.
            // A request never moves to another subscription, so reading its id first is safe.
            $subscriptionId = SeatChangeRequest::query()->whereKey($request->id)->value('tenant_subscription_id');
            $subscription = TenantSubscription::query()->whereKey($subscriptionId)->lockForUpdate()->firstOrFail();
            $request = $this->lockPending($request);
            $today = Pricing::day(CarbonImmutable::today());
            $chargeId = null;

            if ($request->type === SeatRequestType::Increase) {
                $lines = $this->quote($request, $today);
                $term = $subscription->termOn($today);
                $futureTerms = $this->futureTerms($subscription, $today);
                $mostSeats = max([$term->seats ?? 0, ...array_map(fn (SubscriptionTerm $future): int => $future->seats, [...$futureTerms])]);

                if ($term === null || $mostSeats + $request->seats > config('billing.max_seats')) {
                    throw new InvalidSubscriptionChange(__('At most :max seats can be purchased.', ['max' => config('billing.max_seats')]));
                }

                $oldSeats = $term->seats;
                $term->forceFill(['seats' => $term->seats + $request->seats])->save();

                foreach ($futureTerms as $future) {
                    $future->forceFill(['seats' => $future->seats + $request->seats])->save();
                }

                $chargeId = $this->createCharge->handle(
                    $admin, $request->tenant, $subscription, $term, ChargeType::SeatIncrease,
                    "seat-request:{$request->id}",
                    __(':seats additional seats (request #:id)', ['seats' => $request->seats, 'id' => $request->id]),
                    $lines,
                )->id;

                // Buying more seats now cancels an earlier reduction approved for the next renewal;
                // otherwise the renewal would propose fewer seats than were just paid for. The
                // tenant can ask for a new reduction from the increased seat count.
                $superseded = $this->supersedeScheduledReductions($subscription);

                $auditOld = ['seats' => $oldSeats];
                $auditNew = [
                    'seats' => $term->seats,
                    'charged' => Money::format(array_sum(array_column($lines, 'amount')), $subscription->currency),
                    ...($superseded !== [] ? ['superseded_reductions' => $superseded] : []),
                ];
            } else {
                // Requested before an early renewal: it would only apply a year later.
                if ([...$this->futureTerms($subscription, $today)] !== []) {
                    throw new InvalidSubscriptionChange(__('The next term is already confirmed, so this reduction cannot apply at its renewal. Reject the request; the tenant can ask again once that term has started.'));
                }

                // Only the latest approved reduction applies at renewal.
                $this->supersedeScheduledReductions($subscription);

                $auditOld = ['next_term_seats' => null];
                $auditNew = ['next_term_seats' => $request->seats];
            }

            $request->forceFill([
                'status' => SeatRequestStatus::Approved,
                'pending_tenant_id' => null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => $note,
                'billing_charge_id' => $chargeId,
            ])->save();

            PlatformAuditLog::record($admin, $request->tenant, PlatformAuditAction::SeatRequestApproved, $auditOld, [
                'request_id' => $request->id,
                'type' => $request->type->value,
                ...$auditNew,
            ], $note);

            return $request;
        });
    }

    /**
     * @throws InvalidSubscriptionChange
     */
    public function reject(PlatformAdmin $admin, SeatChangeRequest $request, ?string $note = null): SeatChangeRequest
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($admin, $request, $note): SeatChangeRequest {
            $request = $this->lockPending($request);

            $request->forceFill([
                'status' => SeatRequestStatus::Rejected,
                'pending_tenant_id' => null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            PlatformAuditLog::record($admin, $request->tenant, PlatformAuditAction::SeatRequestRejected, newValues: [
                'request_id' => $request->id,
                'type' => $request->type->value,
                'seats' => $request->seats,
            ], reason: $note);

            return $request;
        });
    }

    /**
     * @throws InvalidSubscriptionChange
     */
    protected function lockPending(SeatChangeRequest $request): SeatChangeRequest
    {
        $request = SeatChangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

        if ($request->status !== SeatRequestStatus::Pending) {
            throw new InvalidSubscriptionChange(__('This request was already :status.', ['status' => mb_strtolower($request->status->label())]));
        }

        return $request;
    }

    /**
     * Mark the approved reductions still waiting for a renewal as superseded.
     *
     * @return list<int> The ids of the superseded requests.
     */
    protected function supersedeScheduledReductions(TenantSubscription $subscription): array
    {
        $query = SeatChangeRequest::query()->where('tenant_subscription_id', $subscription->id)->scheduledReduction();
        $ids = array_values($query->clone()->get(['id'])->map(fn (SeatChangeRequest $reduction): int => $reduction->id)->all());

        if ($ids !== []) {
            $query->update(['status' => SeatRequestStatus::Superseded]);
        }

        return $ids;
    }

    /**
     * Terms confirmed by an early renewal that start after the given day.
     *
     * @return iterable<SubscriptionTerm>
     */
    protected function futureTerms(TenantSubscription $subscription, CarbonImmutable $on): iterable
    {
        return SubscriptionTerm::query()
            ->where('tenant_subscription_id', $subscription->id)
            ->whereDate('starts_on', '>', $on->toDateString())
            ->orderBy('sequence')
            ->get();
    }
}
