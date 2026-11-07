<?php

use App\Actions\Billing\ActivateSubscription;
use App\Actions\Billing\IssueMonthlyCharges;
use App\Actions\Billing\RenewSubscription;
use App\Actions\Billing\RequestSeatChange;
use App\Actions\Billing\ReviewSeatChangeRequest;
use App\Enums\ChargeType;
use App\Enums\SeatRequestStatus;
use App\Enums\SeatRequestType;
use App\Enums\SubscriptionStatus;
use App\Exceptions\InvalidSubscriptionChange;
use App\Models\BillingCharge;
use App\Models\PlatformAdmin;
use App\Models\SeatChangeRequest;
use App\Models\SubscriptionTerm;
use App\Models\TenantSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-15 09:00:00'));
    $this->platformAdmin = PlatformAdmin::factory()->create();
    testPrice(1800);
    $this->tenant = provisionTenant('acme', subscribed: false)->tenant;

    // Term 1: 2026-01-15 → 2027-01-15, 5 users at 18.00: setup 1,080.00, then 90.00 a month.
    app(ActivateSubscription::class)->handle($this->platformAdmin, $this->tenant, 5, CarbonImmutable::today());
});

function renew(int $seats, bool $accept = false, int $discountBasisPoints = 0): SubscriptionTerm
{
    return app(RenewSubscription::class)->handle(test()->platformAdmin, test()->tenant, $seats, $accept, discountBasisPoints: $discountBasisPoints);
}

/**
 * Ask for and approve additional seats for the test tenant.
 */
function approveIncrease(int $seats): SeatChangeRequest
{
    tenancy()->initialize(test()->tenant);
    $request = app(RequestSeatChange::class)->handle(superAdminUser(), SeatRequestType::Increase, $seats);
    tenancy()->end();

    return app(ReviewSeatChangeRequest::class)->approve(test()->platformAdmin, $request);
}

test('renewal opens 30 days before the term ends', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-15'));

    expect(fn () => renew(5))->toThrow(InvalidSubscriptionChange::class, 'Renewal opens on 2026-12-16');

    $this->travelTo(CarbonImmutable::parse('2026-12-16'));
    expect(renew(5)->sequence)->toBe(2);
});

test('an on-time renewal starts on the previous renewal date, charges no setup fee and is billed monthly from its start', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-05'));

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.subscriptions.renew', $this->tenant))
        ->assertOk()
        ->assertSee('2027-01-15 to 2028-01-14 (inclusive)')
        ->assertSee('18.00 USD × 5 = 90.00 USD')
        ->assertSee('None: every user already paid a setup fee.')
        ->assertDontSee('The price per user differs');

    $this->actingAs($this->platformAdmin, 'platform')
        ->post(route('platform.subscriptions.renew.store', $this->tenant), ['seats' => 5, 'expected_total' => 9000, 'expected_previous_seats' => 5, 'expected_reduction_id' => 0])
        ->assertRedirect(route('platform.subscriptions.show', $this->tenant))
        ->assertSessionHasNoErrors();

    $terms = SubscriptionTerm::query()->orderBy('sequence')->get();

    expect($terms)->toHaveCount(2)
        ->and($terms[1]->starts_on->toDateString())->toBe('2027-01-15')
        ->and($terms[1]->ends_on->toDateString())->toBe('2028-01-15')
        ->and(BillingCharge::query()->where('type', ChargeType::Setup)->count())->toBe(1)
        ->and(BillingCharge::query()->where('subscription_term_id', $terms[1]->id)->count())->toBe(0)
        // The current term is still the first one until the renewal date.
        ->and($this->tenant->subscription->termOn()->id)->toBe($terms[0]->id);

    $this->travelTo(CarbonImmutable::parse('2027-01-15'));
    app(IssueMonthlyCharges::class)->handle();

    expect($this->tenant->subscription->termOn()->id)->toBe($terms[1]->id)
        ->and($this->tenant->subscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and(BillingCharge::query()->where('subscription_term_id', $terms[1]->id)->sole()->amount)->toBe(9000);
});

test('renewal keeps the history and the previous price snapshot unchanged', function () {
    $first = SubscriptionTerm::query()->sole()->replicate();
    $setupCharge = BillingCharge::query()->where('type', ChargeType::Setup)->sole();
    testPrice(2000);

    $this->travelTo(CarbonImmutable::parse('2027-01-10'));
    renew(5, accept: true);

    $terms = SubscriptionTerm::query()->orderBy('sequence')->get();

    expect($terms[0]->seat_price)->toBe($first->seat_price)
        ->and($terms[1]->seat_price)->toBe(2000)
        ->and($terms[1]->monthlyFee())->toBe(10000)
        ->and($setupCharge->refresh()->amount)->toBe(108000);
});

test('a renewal is refused when the price per user changed after the preview', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-05'));

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.subscriptions.renew', $this->tenant))
        ->assertSee('name="expected_total" value="9000"', false);

    // The price changes before the previewed renewal is confirmed.
    testPrice(2000);

    $this->actingAs($this->platformAdmin, 'platform')
        ->post(route('platform.subscriptions.renew.store', $this->tenant), ['seats' => 5, 'accept_pricing_change' => '1', 'expected_total' => 9000, 'expected_previous_seats' => 5, 'expected_reduction_id' => 0])
        ->assertSessionHasErrors(['subscription' => 'The price per user changed after the preview: the monthly charge is now 100.00 USD. Review it and confirm again.']);

    expect(SubscriptionTerm::query()->count())->toBe(1);
});

test('a changed price per user must be shown and explicitly confirmed', function () {
    testPrice(2000);
    $this->travelTo(CarbonImmutable::parse('2027-01-10'));

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.subscriptions.renew', $this->tenant))
        ->assertOk()
        ->assertSee('The price per user differs from the current term.')
        ->assertSee('goes from 90.00 USD to 100.00 USD', false);

    $this->actingAs($this->platformAdmin, 'platform')
        ->post(route('platform.subscriptions.renew.store', $this->tenant), ['seats' => 5, 'expected_total' => 10000, 'expected_previous_seats' => 5, 'expected_reduction_id' => 0])
        ->assertSessionHasErrors('subscription');

    expect(SubscriptionTerm::query()->count())->toBe(1);

    $this->actingAs($this->platformAdmin, 'platform')
        ->post(route('platform.subscriptions.renew.store', $this->tenant), ['seats' => 5, 'accept_pricing_change' => '1', 'expected_total' => 10000, 'expected_previous_seats' => 5, 'expected_reduction_id' => 0])
        ->assertSessionHasNoErrors();

    expect(SubscriptionTerm::query()->count())->toBe(2);
});

test('a renewal cannot switch the subscription to another currency', function () {
    testPrice(1800, 'EUR');
    $this->travelTo(CarbonImmutable::parse('2027-01-10'));

    expect(fn () => renew(5, accept: true))->toThrow(InvalidSubscriptionChange::class, 'Currencies cannot be mixed');
});

test('users above those that already paid a setup fee pay it for twelve months at renewal', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-10'));

    $term = renew(8, discountBasisPoints: 5000);
    $setup = BillingCharge::query()->where('subscription_term_id', $term->id)->where('type', ChargeType::Setup)->with('lines')->sole();

    expect($setup->subtotal)->toBe(64800) // 18 × 12 × 3 new users
        ->and($setup->lines->sole()->quantity)->toBe(3)
        ->and($setup->amount)->toBe(32400) // 50% discount
        ->and(TenantSubscription::query()->sole()->setup_paid_seats)->toBe(8);
});

test('an approved reduction applies only at renewal', function () {
    tenancy()->initialize($this->tenant);
    $request = app(RequestSeatChange::class)->handle(superAdminUser(), SeatRequestType::Reduction, 2);
    tenancy()->end();
    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $request);

    expect(SubscriptionTerm::query()->sole()->seats)->toBe(5);

    $this->travelTo(CarbonImmutable::parse('2027-01-10'));
    $quote = app(RenewSubscription::class)->quote($this->tenant);
    expect($quote->seats)->toBe(2)
        ->and($quote->setupSeats)->toBe(0)
        ->and($quote->scheduledReduction?->id)->toBe($request->id);

    $term = renew($quote->seats);

    expect($term->seats)->toBe(2)
        ->and($term->monthlyFee())->toBe(3600)
        ->and($request->refresh()->status)->toBe(SeatRequestStatus::Applied)
        ->and($request->applied_to_term_id)->toBe($term->id)
        ->and(BillingCharge::query()->where('type', ChargeType::Setup)->count())->toBe(1)
        ->and(TenantSubscription::query()->sole()->setup_paid_seats)->toBe(5)
        ->and(SubscriptionTerm::query()->where('sequence', 1)->sole()->seats)->toBe(5);
});

test('seats bought back after a reduction pay no second setup fee', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-10'));
    renew(2);

    // Back to 5 users in the third month of the new term: they all paid a setup fee already.
    $this->travelTo(CarbonImmutable::parse('2027-03-20'));
    expect(approveIncrease(3)->billing_charge_id)->toBeNull();

    // One more is new: 18.00 × 10 remaining months (March counts in full) × 1.
    $charge = BillingCharge::query()->find(approveIncrease(1)->billing_charge_id);

    expect($charge->type)->toBe(ChargeType::SeatSetup)
        ->and($charge->amount)->toBe(18000)
        ->and($this->tenant->subscription->termOn()->seats)->toBe(6)
        ->and(TenantSubscription::query()->sole()->setup_paid_seats)->toBe(6);
});

test('a renewal can never reduce seats below the active users', function () {
    $this->tenant->run(fn () => User::factory()->count(3)->create()); // 4 active users with the admin
    $this->travelTo(CarbonImmutable::parse('2027-01-10'));

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.subscriptions.renew', [$this->tenant, 'seats' => 3]))
        ->assertOk()
        ->assertSee('4 users are active, more than the 3 seats proposed');

    expect(fn () => renew(3))->toThrow(InvalidSubscriptionChange::class, '4 users are active');
    expect(SubscriptionTerm::query()->count())->toBe(1);

    // Nobody was deactivated to make it fit.
    expect($this->tenant->run(fn () => User::query()->active()->count()))->toBe(4)
        ->and(renew(4)->seats)->toBe(4);
});

test('a renewal cannot be confirmed twice', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-10'));
    renew(5);

    expect(fn () => renew(5))->toThrow(InvalidSubscriptionChange::class, 'Renewal opens on 2027-12-16');

    $this->actingAs($this->platformAdmin, 'platform')
        ->post(route('platform.subscriptions.renew.store', $this->tenant), ['seats' => 5, 'expected_total' => 9000, 'expected_previous_seats' => 5, 'expected_reduction_id' => 0])
        ->assertSessionHasErrors('subscription');

    expect(SubscriptionTerm::query()->count())->toBe(2);
});

test('an expired subscription renewed late starts on the day of renewal and its first month is due at once', function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-20'));
    expect($this->tenant->subscriptionStatus())->toBe(SubscriptionStatus::Expired);

    $term = renew(5);
    $monthly = BillingCharge::query()->where('subscription_term_id', $term->id)->sole();

    expect($term->starts_on->toDateString())->toBe('2027-02-20')
        ->and($term->ends_on->toDateString())->toBe('2028-02-20')
        ->and($this->tenant->subscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($monthly->type)->toBe(ChargeType::Monthly)
        ->and($monthly->period_starts_on->toDateString())->toBe('2027-02-20');
});

test('seats added after an early renewal are also added to the renewed term and pay one setup fee', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-05'));
    $next = renew(5);

    $charge = BillingCharge::query()->with('lines')->find(approveIncrease(2)->billing_charge_id);

    // The last month of the current term has started: 18.00 × 1 month × 2 users.
    expect($charge->type)->toBe(ChargeType::SeatSetup)
        ->and($charge->lines->sole()->calculation['remaining_months'])->toBe(1)
        ->and($charge->amount)->toBe(3600)
        ->and(SubscriptionTerm::query()->where('sequence', 1)->sole()->seats)->toBe(7)
        ->and($next->refresh()->seats)->toBe(7)
        ->and(TenantSubscription::query()->sole()->setup_paid_seats)->toBe(7);
});

test('the renewal preview is reachable only for tenants with a subscription', function () {
    $other = provisionTenant('globex', subscribed: false)->tenant;

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.subscriptions.renew', $other))
        ->assertRedirect(route('platform.subscriptions.show', $other))
        ->assertSessionHas('error');

    expect(SeatChangeRequest::query()->count())->toBe(0);
});
