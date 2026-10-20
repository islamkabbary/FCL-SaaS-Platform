<?php

namespace App\Actions\Billing;

use App\Billing\Pricing;
use App\Enums\PlatformAuditAction;
use App\Enums\SeatRequestStatus;
use App\Enums\SeatRequestType;
use App\Enums\TenantPermission;
use App\Exceptions\InvalidSubscriptionChange;
use App\Models\PlatformAuditLog;
use App\Models\SeatChangeRequest;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * A tenant user asks for more seats now, or for fewer seats from the next renewal.
 *
 * Runs in tenant context: the tenant is always the current one, never taken from
 * input, so a request can only ever concern the requester's own company. Nothing
 * changes until a Platform Admin approves the request.
 */
class RequestSeatChange
{
    /**
     * @throws AuthorizationException
     * @throws InvalidSubscriptionChange
     */
    public function handle(User $actor, SeatRequestType $type, int $seats, ?string $note = null): SeatChangeRequest
    {
        if ($actor->cannot(TenantPermission::BillingManage->value)) {
            throw new AuthorizationException(__('You are not allowed to request seat changes.'));
        }

        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw new LogicException('Seat changes can only be requested in tenant context.');
        }

        $subscription = TenantSubscription::query()->where('tenant_id', $tenant->getTenantKey())->first();
        $today = CarbonImmutable::today();
        $term = $subscription?->termOn($today)
            ?? throw new InvalidSubscriptionChange(__('Seat changes can only be requested during an active subscription term.'));

        $maxSeats = (int) config('billing.max_seats');
        // An early renewal may already have confirmed a later term; an approved increase is
        // added to it too, so its seats count against the maximum as well.
        $latest = $subscription->latestTerm() ?? $term;
        $mostSeats = max($term->seats, $latest->seats);

        if ($type === SeatRequestType::Increase) {
            if ($seats < 1 || $mostSeats + $seats > $maxSeats) {
                throw new InvalidSubscriptionChange(__('Request between 1 and :max additional seats.', ['max' => max(1, $maxSeats - $mostSeats)]));
            }

            // An estimate only: the charge is computed again on the day of approval.
            $estimate = Pricing::prorate($seats, $term->annual_seat_price, $term->starts_on, $term->ends_on, $today)->amount;
        } else {
            // A reduction applies at the next renewal. Once that renewal is confirmed, it would
            // only apply a year later and be compared with the wrong term, so it waits.
            if ($latest->id !== $term->id) {
                throw new InvalidSubscriptionChange(__('Your renewal from :date is already confirmed with :seats seats. A reduction can be requested once that term has started.', [
                    'date' => $latest->starts_on->toDateString(),
                    'seats' => $latest->seats,
                ]));
            }

            if ($seats < 1 || $seats >= $term->seats) {
                throw new InvalidSubscriptionChange(__('A reduction must be to between 1 and :max seats (fewer than the current :seats).', [
                    'max' => max(1, $term->seats - 1),
                    'seats' => $term->seats,
                ]));
            }

            $estimate = null;
        }

        try {
            $request = SeatChangeRequest::query()->create([
                'tenant_id' => $tenant->getTenantKey(),
                'tenant_subscription_id' => $subscription->id,
                'subscription_term_id' => $term->id,
                'type' => $type,
                'seats' => $seats,
                'status' => SeatRequestStatus::Pending,
                'pending_tenant_id' => $tenant->getTenantKey(),
                'requested_by_user_id' => $actor->getKey(),
                'requested_by_name' => $actor->name,
                'requested_by_email' => $actor->email,
                'request_note' => $note,
                'currency' => $subscription->currency,
                'estimated_amount' => $estimate,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new InvalidSubscriptionChange(__('A seat request is already pending. Wait for it to be reviewed or cancel it first.'));
        }

        PlatformAuditLog::recordForTenantUser(
            $actor,
            $tenant,
            $type === SeatRequestType::Increase ? PlatformAuditAction::SeatIncreaseRequested : PlatformAuditAction::SeatReductionRequested,
            ['request_id' => $request->id, 'seats' => $seats, 'current_seats' => $term->seats, 'estimated_amount' => $estimate, 'currency' => $request->currency],
            $note,
        );

        return $request;
    }

    /**
     * Withdraw the current tenant's pending request.
     *
     * @throws AuthorizationException
     * @throws InvalidSubscriptionChange
     */
    public function cancel(User $actor, int $requestId): void
    {
        if ($actor->cannot(TenantPermission::BillingManage->value)) {
            throw new AuthorizationException(__('You are not allowed to request seat changes.'));
        }

        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw new LogicException('Seat requests can only be cancelled in tenant context.');
        }

        // Scoped to the current tenant: another tenant's request id is simply not found.
        $updated = SeatChangeRequest::query()
            ->whereKey($requestId)
            ->where('tenant_id', $tenant->getTenantKey())
            ->pending()
            ->update(['status' => SeatRequestStatus::Cancelled, 'pending_tenant_id' => null]);

        if ($updated !== 1) {
            throw new InvalidSubscriptionChange(__('This request is no longer pending.'));
        }

        PlatformAuditLog::recordForTenantUser($actor, $tenant, PlatformAuditAction::SeatRequestCancelled, ['request_id' => $requestId]);
    }
}
