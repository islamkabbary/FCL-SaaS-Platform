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
    testPrice(1800);
    $this->tenant = provisionTenant('acme', subscribed: false)->tenant;

    // Term 1: 2026-01-15 → 2027-01-15, 5 users at 18.00 a month.
    app(ActivateSubscription::class)->handle($this->platformAdmin, $this->tenant, 5, CarbonImmutable::today());
});

test('with the next term confirmed, the estimate is the setup fee of the current term, as charged', function () {
    // Renewed early: term 2 (2027-01-15 → 2028-01-15) is confirmed; the last month of term 1 has started.
    $this->travelTo(CarbonImmutable::parse('2027-01-05'));
    app(RenewSubscription::class)->handle($this->platformAdmin, $this->tenant, 5, false);

    tenancy()->initialize($this->tenant);
    $admin = tenantAdminUser();

    Livewire::actingAs($admin)
        ->test('pages::subscription.index')
        ->set('increaseSeats', '2')
        ->assertSee('Estimated setup fee if approved today: 36.00 USD'); // 18.00 × 1 month × 2

    $request = app(RequestSeatChange::class)->handle($admin, SeatRequestType::Increase, 2);
    tenancy()->end();

    expect($request->estimated_amount)->toBe(3600);

    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $request);

    expect(BillingCharge::query()->where('type', ChargeType::SeatSetup)->sole()->amount)->toBe($request->estimated_amount);
});

test('without a confirmed next term, the estimate is the setup fee for the remaining months', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-15'));

    tenancy()->initialize($this->tenant);
    $admin = tenantAdminUser();

    Livewire::actingAs($admin)
        ->test('pages::subscription.index')
        ->set('increaseSeats', '2')
        ->assertSee('Estimated setup fee if approved today: 216.00 USD') // 18.00 × 6 months × 2
        ->assertSee('Then 36.00 USD more each month.');
});
