<?php

use App\Actions\Billing\ActivateSubscription;
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
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTerm;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-15 09:00:00'));
    $this->platformAdmin = PlatformAdmin::factory()->create();
    $this->plan = testPlan(['name' => 'Standard']);
    $this->tenant = provisionTenant('acme', subscribed: false)->tenant;

    // Term 1: 2026-01-15 → 2027-01-15, 5 seats, 1,000.00 + 5 × 120.00.
    app(ActivateSubscription::class)->handle($this->platformAdmin, $this->tenant, $this->plan, 5, CarbonImmutable::today());
});

function renew(int $seats, ?SubscriptionPlan $plan = null, bool $accept = false): SubscriptionTerm
{
    return app(RenewSubscription::class)->handle(test()->platformAdmin, test()->tenant, $plan ?? test()->plan, $seats, $accept);
}

test('renewal opens 30 days before the term ends', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-15'));

    expect(fn () => renew(5))->toThrow(InvalidSubscriptionChange::class, 'Renewal opens on 2026-12-16');

    $this->travelTo(CarbonImmutable::parse('2026-12-16'));
    expect(renew(5)->sequence)->toBe(2);
});

test('an on-time renewal starts on the previous renewal date and charges the new annual total', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-05'));

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.subscriptions.renew', $this->tenant))
        ->assertOk()
        ->assertSee('2027-01-15 to 2028-01-14 (inclusive)')
        ->assertSee('1,000.00 USD + 5 × 120.00 USD = 1,600.00 USD')
        ->assertDontSee('The plan or prices differ');

    $this->actingAs($this->platformAdmin, 'platform')
        ->post(route('platform.subscriptions.renew.store', $this->tenant), ['plan_id' => $this->plan->id, 'seats' => 5, 'expected_total' => 160000, 'expected_previous_seats' => 5, 'expected_reduction_id' => 0])
        ->assertRedirect(route('platform.subscriptions.show', $this->tenant))
        ->assertSessionHasNoErrors();

    $terms = SubscriptionTerm::query()->orderBy('sequence')->get();
    $renewalCharge = BillingCharge::query()->where('type', ChargeType::Renewal)->sole();

    expect($terms)->toHaveCount(2)
        ->and($terms[1]->starts_on->toDateString())->toBe('2027-01-15')
        ->and($terms[1]->ends_on->toDateString())->toBe('2028-01-15')
        ->and($renewalCharge->amount)->toBe(160000)
        ->and($renewalCharge->subscription_term_id)->toBe($terms[1]->id)
        // The current term is still the first one until the renewal date.
        ->and($this->tenant->subscription->termOn()->id)->toBe($terms[0]->id);

    $this->travelTo(CarbonImmutable::parse('2027-01-15'));
    expect($this->tenant->subscription->termOn()->id)->toBe($terms[1]->id)
        ->and($this->tenant->subscriptionStatus())->toBe(SubscriptionStatus::Active);
});

test('renewal keeps the history and the previous pricing snapshot unchanged', function () {
    $first = SubscriptionTerm::query()->sole()->replicate();
    $initialCharge = BillingCharge::query()->sole();
    $this->plan->update(['annual_base_fee' => 150000, 'annual_seat_price' => 15000]);

    $this->travelTo(CarbonImmutable::parse('2027-01-10'));
    renew(5, accept: true);

    $terms = SubscriptionTerm::query()->orderBy('sequence')->get();

    expect($terms[0]->annual_base_fee)->toBe($first->annual_base_fee)
        ->and($terms[0]->annual_seat_price)->toBe($first->annual_seat_price)
        ->and($terms[1]->annual_base_fee)->toBe(150000)
        ->and($terms[1]->annual_seat_price)->toBe(15000)
        ->and($initialCharge->refresh()->amount)->toBe(160000)
        ->and(BillingCharge::query()->where('type', ChargeType::Renewal)->sole()->amount)->toBe(225000);
});

test('a renewal is refused when the plan prices changed after the preview', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-05'));

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.subscriptions.renew', $this->tenant))
        ->assertSee('name="expected_total" value="160000"', false);

    // The plan's prices change before the previewed renewal is confirmed.
    $this->plan->update(['annual_seat_price' => 15000]);

    $this->actingAs($this->platformAdmin, 'platform')
        ->post(route('platform.subscriptions.renew.store', $this->tenant), ['plan_id' => $this->plan->id, 'seats' => 5, 'accept_pricing_change' => '1', 'expected_total' => 160000, 'expected_previous_seats' => 5, 'expected_reduction_id' => 0])
        ->assertSessionHasErrors(['subscription' => 'The plan\'s prices changed after the preview: the annual total is now 1,750.00 USD. Review it and confirm again.']);

    expect(SubscriptionTerm::query()->count())->toBe(1)
        ->and(BillingCharge::query()->where('type', ChargeType::Renewal)->count())->toBe(0);
});

test('changed plan prices must be shown and explicitly confirmed', function () {
    $this->plan->update(['annual_seat_price' => 15000]);
    $this->travelTo(CarbonImmutable::parse('2027-01-10'));

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.subscriptions.renew', $this->tenant))
        ->assertOk()
        ->assertSee('The plan or prices differ from the current term.')
        ->assertSee('the same seats would cost 1,600.00 USD; the new total is 1,750.00 USD', false);

    $this->actingAs($this->platformAdmin, 'platform')
        ->post(route('platform.subscriptions.renew.store', $this->tenant), ['plan_id' => $this->plan->id, 'seats' => 5, 'expected_total' => 175000, 'expected_previous_seats' => 5, 'expected_reduction_id' => 0])
        ->assertSessionHasErrors('subscription');

    expect(SubscriptionTerm::query()->count())->toBe(1);

    $this->actingAs($this->platformAdmin, 'platform')
        ->post(route('platform.subscriptions.renew.store', $this->tenant), ['plan_id' => $this->plan->id, 'seats' => 5, 'accept_pricing_change' => '1', 'expected_total' => 175000, 'expected_previous_seats' => 5, 'expected_reduction_id' => 0])
        ->assertSessionHasNoErrors();

    expect(SubscriptionTerm::query()->count())->toBe(2);
});

test('a renewal cannot switch to a plan in another currency', function () {
    $euroPlan = testPlan(['currency' => 'EUR']);
    $this->travelTo(CarbonImmutable::parse('2027-01-10'));

    expect(fn () => renew(5, $euroPlan, accept: true))->toThrow(InvalidSubscriptionChange::class, 'Currencies cannot be mixed');
});

test('an approved reduction applies only at renewal', function () {
    tenancy()->initialize($this->tenant);
    $request = app(RequestSeatChange::class)->handle(tenantAdminUser(), SeatRequestType::Reduction, 2);
    tenancy()->end();
    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $request);

    expect(SubscriptionTerm::query()->sole()->seats)->toBe(5);

    $this->travelTo(CarbonImmutable::parse('2027-01-10'));
    $quote = app(RenewSubscription::class)->quote($this->tenant);
    expect($quote->seats)->toBe(2)
        ->and($quote->scheduledReduction?->id)->toBe($request->id);

    $term = renew($quote->seats);

    expect($term->seats)->toBe(2)
        ->and($request->refresh()->status)->toBe(SeatRequestStatus::Applied)
        ->and($request->applied_to_term_id)->toBe($term->id)
        ->and(BillingCharge::query()->where('type', ChargeType::Renewal)->sole()->amount)->toBe(124000) // 1,000 + 2 × 120
        ->and(SubscriptionTerm::query()->where('sequence', 1)->sole()->seats)->toBe(5);
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
        ->post(route('platform.subscriptions.renew.store', $this->tenant), ['plan_id' => $this->plan->id, 'seats' => 5, 'expected_total' => 160000, 'expected_previous_seats' => 5, 'expected_reduction_id' => 0])
        ->assertSessionHasErrors('subscription');

    expect(SubscriptionTerm::query()->count())->toBe(2)
        ->and(BillingCharge::query()->where('type', ChargeType::Renewal)->count())->toBe(1);
});

test('an expired subscription renewed late starts on the day of renewal', function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-20'));
    expect($this->tenant->subscriptionStatus())->toBe(SubscriptionStatus::Expired);

    $term = renew(5);

    expect($term->starts_on->toDateString())->toBe('2027-02-20')
        ->and($term->ends_on->toDateString())->toBe('2028-02-20')
        ->and($this->tenant->subscriptionStatus())->toBe(SubscriptionStatus::Active);
});

test('seats added after an early renewal are also added to the renewed term', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-05'));
    $next = renew(5);

    tenancy()->initialize($this->tenant);
    $request = app(RequestSeatChange::class)->handle(tenantAdminUser(), SeatRequestType::Increase, 2);
    tenancy()->end();
    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $request);

    $charge = BillingCharge::query()->where('type', ChargeType::SeatIncrease)->with('lines')->sole();

    // 10 of 365 days of the current term, plus the whole renewed term.
    expect($charge->lines->pluck('amount')->all())->toBe([658, 24000]) // 2 × 12000 × 10/365 = 657.53 → 658
        ->and($charge->amount)->toBe(24658)
        ->and(SubscriptionTerm::query()->where('sequence', 1)->sole()->seats)->toBe(7)
        ->and($next->refresh()->seats)->toBe(7);
});

test('the renewal preview is reachable only for tenants with a subscription', function () {
    $other = provisionTenant('globex', subscribed: false)->tenant;

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.subscriptions.renew', $other))
        ->assertRedirect(route('platform.subscriptions.show', $other))
        ->assertSessionHas('error');

    expect(SeatChangeRequest::query()->count())->toBe(0);
});
