<?php

/*
 * Review proofs (2026-10-19), see docs/FCL_SaaS_MVP_Review_Report_2026-10-19.md.
 * Each test asserts the CORRECT behaviour, so it fails while the defect it documents
 * exists. They are opt-in so the regular suite stays green:
 *
 *   RUN_REVIEW_PROOFS=1 php vendor/bin/pest tests/Feature/Review
 *
 * When a finding is fixed, move its test to the permanent suite.
 */

use App\Actions\Billing\ActivateSubscription;
use App\Actions\Billing\RenewSubscription;
use App\Actions\Billing\RequestSeatChange;
use App\Actions\Billing\ReviewSeatChangeRequest;
use App\Actions\Employees\CreateEmployee;
use App\Enums\SeatRequestType;
use App\Exceptions\NoSeatAvailable;
use App\Models\PlatformAdmin;
use Carbon\CarbonImmutable;

beforeEach(function () {
    if (! getenv('RUN_REVIEW_PROOFS')) {
        $this->markTestSkipped('Review proof: run with RUN_REVIEW_PROOFS=1.');
    }
});

test('[B1] users cannot be added beyond the seats of an already confirmed next term', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-15 09:00:00'));
    $platformAdmin = PlatformAdmin::factory()->create();
    $plan = testPlan();
    $tenant = provisionTenant('acme', subscribed: false)->tenant;
    app(ActivateSubscription::class)->handle($platformAdmin, $tenant, $plan, 5, CarbonImmutable::today());

    // Only the Tenant Admin is active. A reduction to 2 seats is approved.
    tenancy()->initialize($tenant);
    $admin = tenantAdminUser();
    $reduction = app(RequestSeatChange::class)->handle($admin, SeatRequestType::Reduction, 2);
    tenancy()->end();
    app(ReviewSeatChangeRequest::class)->approve($platformAdmin, $reduction);

    // Early renewal for 2 seats (term 2 starts 2027-01-15).
    $this->travelTo(CarbonImmutable::parse('2026-12-20'));
    app(RenewSubscription::class)->handle($platformAdmin, $tenant, $plan, 2, false);

    tenancy()->initialize($tenant);
    app(CreateEmployee::class)->handle($admin, ['name' => 'One', 'email' => 'one@acme.test']);

    // A third active user would exceed the 2 seats already confirmed for term 2.
    expect(fn () => app(CreateEmployee::class)->handle($admin, ['name' => 'Two', 'email' => 'two@acme.test']))
        ->toThrow(NoSeatAvailable::class);
});

test('[B2] an approved increase cancels an earlier scheduled reduction', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00'));
    $platformAdmin = PlatformAdmin::factory()->create();
    $tenant = actingInTenant(subscribed: false);
    subscribeTenant($tenant, seats: 2, daysAgo: 184); // term 2026-03-01 → 2027-03-01
    $admin = tenantAdminUser();

    $reduction = app(RequestSeatChange::class)->handle($admin, SeatRequestType::Reduction, 1);
    app(ReviewSeatChangeRequest::class)->approve($platformAdmin, $reduction);
    $increase = app(RequestSeatChange::class)->handle($admin, SeatRequestType::Increase, 3);
    app(ReviewSeatChangeRequest::class)->approve($platformAdmin, $increase); // term now has 5 seats

    $this->travelTo(CarbonImmutable::parse('2027-02-15'));
    $quote = app(RenewSubscription::class)->quote($tenant);

    expect($quote->scheduledReduction)->toBeNull()
        ->and($quote->seats)->toBe(5);
});
