<?php

use App\Actions\Billing\ActivateSubscription;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\RenewSubscription;
use App\Actions\Billing\RequestSeatChange;
use App\Actions\Billing\ReviewSeatChangeRequest;
use App\Enums\SeatRequestStatus;
use App\Enums\SeatRequestType;
use App\Exceptions\InvalidSubscriptionChange;
use App\Models\BillingCharge;
use App\Models\BillingPayment;
use App\Models\PlatformAdmin;
use App\Models\SeatChangeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Milestone 5.2: approvals and payments are checked against the state they apply to.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-15 09:00:00'));
    $this->platformAdmin = PlatformAdmin::factory()->create();
    $this->plan = testPlan(['name' => 'Standard']);
    $this->tenant = provisionTenant('acme', subscribed: false)->tenant;

    // Term 1: 2026-01-15 → 2027-01-15, 5 seats; its charge is issued on 2026-01-15.
    app(ActivateSubscription::class)->handle($this->platformAdmin, $this->tenant, $this->plan, 5, CarbonImmutable::today());
    $this->charge = BillingCharge::query()->sole();
});

function pendingReduction(int $seats): SeatChangeRequest
{
    tenancy()->initialize(test()->tenant);
    $request = app(RequestSeatChange::class)->handle(tenantAdminUser(), SeatRequestType::Reduction, $seats);
    tenancy()->end();

    return $request;
}

test('a reduction requested in an earlier term cannot be approved in a later one', function (int $requestedSeats, int $renewedSeats) {
    $reduction = pendingReduction($requestedSeats);

    // Renewed early while the reduction waited; once term 2 has started the request is stale.
    $this->travelTo(CarbonImmutable::parse('2027-01-05'));
    app(RenewSubscription::class)->handle($this->platformAdmin, $this->tenant, $this->plan, $renewedSeats, false);
    $this->travelTo(CarbonImmutable::parse('2027-01-20'));

    expect(fn () => app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $reduction))
        ->toThrow(InvalidSubscriptionChange::class, 'This reduction was requested for an earlier term');

    expect($reduction->refresh()->status)->toBe(SeatRequestStatus::Pending);

    // It can still be rejected, and the tenant can ask again for the current term.
    app(ReviewSeatChangeRequest::class)->reject($this->platformAdmin, $reduction);
    expect($reduction->refresh()->status)->toBe(SeatRequestStatus::Rejected);
})->with([
    'now an increase' => [4, 3],
    'still lower than the new term' => [2, 3],
]);

test('a reduction for the current term is approved as before', function () {
    $reduction = pendingReduction(3);

    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $reduction);

    expect($reduction->refresh()->status)->toBe(SeatRequestStatus::Approved);
});

test('a payment cannot be dated before its charge was issued', function () {
    expect(fn () => app(RecordPayment::class)->handle($this->platformAdmin, $this->charge, 10000, CarbonImmutable::parse('2026-01-14'), null, null, (string) Str::uuid()))
        ->toThrow(InvalidSubscriptionChange::class, 'The payment date cannot be before the charge was issued (2026-01-15).');

    app(RecordPayment::class)->handle($this->platformAdmin, $this->charge, 10000, CarbonImmutable::parse('2026-01-15'), null, null, (string) Str::uuid());

    expect(BillingPayment::query()->count())->toBe(1);
});

test('one payment form submitted for two charges at the same moment gives the friendly message, not a server error', function () {
    $token = (string) Str::uuid();
    $other = BillingCharge::query()->create([
        ...$this->charge->only(['tenant_id', 'tenant_subscription_id', 'subscription_term_id', 'type', 'currency', 'amount', 'description']),
        'idempotency_key' => 'other-charge',
        'issued_on' => '2026-01-15',
    ]);

    // The concurrent request for the other charge commits its payment with the same token
    // after this one checked the token, just before this one inserts.
    BillingPayment::creating(function (BillingPayment $payment) use ($other, $token): void {
        if ($payment->billing_charge_id !== $other->id) {
            DB::table('billing_payments')->insert([
                ...collect($payment->getAttributes())->except(['created_at'])->all(),
                'billing_charge_id' => $other->id,
                'paid_on' => '2026-01-15',
                'submission_token' => $token,
                'created_at' => now(),
            ]);
        }
    });

    expect(fn () => app(RecordPayment::class)->handle($this->platformAdmin, $this->charge, 10000, CarbonImmutable::today(), null, null, $token))
        ->toThrow(InvalidSubscriptionChange::class, 'This payment form was already used for another charge.');
});

test('the renewal preview refuses an unknown plan instead of falling back to the current one', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-05'));

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.subscriptions.renew', ['tenant' => $this->tenant, 'plan_id' => 999999]))
        ->assertSessionHasErrors('plan_id');
});
