<?php

use App\Actions\Billing\ActivateSubscription;
use App\Actions\Billing\RenewSubscription;
use App\Actions\Billing\RequestSeatChange;
use App\Actions\Billing\ReviewSeatChangeRequest;
use App\Enums\ChargeType;
use App\Enums\SeatRequestType;
use App\Models\BillingCharge;
use App\Models\PlatformAdmin;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// Milestone 5.2: the estimate a tenant sees for additional seats is what the approval
// charges, also when the next term was already confirmed by an early renewal.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-15 09:00:00'));
    $this->platformAdmin = PlatformAdmin::factory()->create();
    $this->plan = testPlan(['name' => 'Standard']);
    $this->tenant = provisionTenant('acme', subscribed: false)->tenant;

    // Term 1: 2026-01-15 → 2027-01-15, 5 seats at 120.00 a seat.
    app(ActivateSubscription::class)->handle($this->platformAdmin, $this->tenant, $this->plan, 5, CarbonImmutable::today());
});

test('with the next term confirmed, the estimate includes the full renewed term', function () {
    // Renewed early: term 2 (2027-01-15 → 2028-01-15) is confirmed; 10 days of term 1 remain.
    $this->travelTo(CarbonImmutable::parse('2027-01-05'));
    app(RenewSubscription::class)->handle($this->platformAdmin, $this->tenant, $this->plan, 5, false);

    tenancy()->initialize($this->tenant);
    $admin = tenantAdminUser();

    Livewire::actingAs($admin)
        ->test('pages::subscription.index')
        ->set('increaseSeats', '2')
        // 2 × 120.00 × 10/365 = 6.58 for the current term, plus 2 × 120.00 for term 2.
        ->assertSee('Estimated charge if approved today: 246.58 USD')
        ->assertSee('2 additional seats, renewed term 2027-01-15 – 2028-01-14: 240.00 USD');

    $request = app(RequestSeatChange::class)->handle($admin, SeatRequestType::Increase, 2);
    tenancy()->end();

    expect($request->estimated_amount)->toBe(24658);

    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $request);

    expect(BillingCharge::query()->where('type', ChargeType::SeatIncrease)->sole()->amount)->toBe($request->estimated_amount);
});

test('without a confirmed next term, the estimate is the prorated current term only', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-15'));

    tenancy()->initialize($this->tenant);
    $admin = tenantAdminUser();

    Livewire::actingAs($admin)
        ->test('pages::subscription.index')
        ->set('increaseSeats', '2')
        ->assertSee('Estimated charge if approved today: 120.99 USD') // 2 × 120.00 × 184/365
        ->assertDontSee('renewed term');
});
