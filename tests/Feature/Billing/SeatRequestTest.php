<?php

use App\Actions\Billing\RenewSubscription;
use App\Actions\Billing\RequestSeatChange;
use App\Actions\Billing\ReviewSeatChangeRequest;
use App\Actions\Employees\CreateEmployee;
use App\Billing\SeatGuard;
use App\Enums\ChargeType;
use App\Enums\PlatformAuditAction;
use App\Enums\SeatRequestStatus;
use App\Enums\SeatRequestType;
use App\Enums\TenantPermission;
use App\Exceptions\InvalidSubscriptionChange;
use App\Models\BillingCharge;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SeatChangeRequest;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTerm;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

beforeEach(function () {
    // Term 2026-03-01 → 2027-03-01 (365 days); today is 2026-09-01: 181 days remain.
    $this->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00'));
    $this->platformAdmin = PlatformAdmin::factory()->create();
    $this->tenant = actingInTenant(subscribed: false);
    $this->term = subscribeTenant($this->tenant, seats: 2, daysAgo: 184);
    $this->admin = tenantAdminUser();
});

function requestSeats(SeatRequestType $type, int $seats): SeatChangeRequest
{
    return app(RequestSeatChange::class)->handle(test()->admin, $type, $seats);
}

test('a tenant admin sees the subscription and requests additional seats with an estimate', function () {
    expect($this->term->starts_on->toDateString())->toBe('2026-03-01');

    Livewire::actingAs($this->admin)
        ->test('pages::subscription.index')
        ->assertSee($this->term->plan_name)
        ->assertSee('Mar 1, 2027')
        ->assertSee('120.00 USD')
        ->assertSeeInOrder(['Purchased seats', '2', 'Active users (seats in use)', '1', 'Available seats', '1'])
        ->set('increaseSeats', '3')
        ->assertSee('Estimated charge if approved today: 178.52 USD') // 3 × 120.00 × 181/365
        ->call('requestIncrease')
        ->assertHasNoErrors();

    $request = SeatChangeRequest::query()->sole();

    expect($request->tenant_id)->toBe($this->tenant->id)
        ->and($request->type)->toBe(SeatRequestType::Increase)
        ->and($request->seats)->toBe(3)
        ->and($request->status)->toBe(SeatRequestStatus::Pending)
        ->and($request->requested_by_user_id)->toBe($this->admin->id)
        ->and($request->requested_by_email)->toBe('admin@acme.test')
        ->and($request->estimated_amount)->toBe(17852)
        ->and($this->term->refresh()->seats)->toBe(2);

    $entry = PlatformAuditLog::query()->sole();
    expect($entry->action)->toBe(PlatformAuditAction::SeatIncreaseRequested)
        ->and($entry->actor_type)->toBe(PlatformAuditLog::ActorTenantUser)
        ->and($entry->tenant_user_id)->toBe($this->admin->id)
        ->and($entry->platform_admin_id)->toBeNull();
});

test('approval adds the seats to the current term and charges them prorated', function () {
    $request = requestSeats(SeatRequestType::Increase, 3);

    $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00:00')); // 90 days remain

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.seat-requests.index'))
        ->assertOk()
        ->assertSee('Charge if approved today')
        ->assertSee('88.77 USD'); // 3 × 12000 × 90 / 365 = 8876.71… → 8877

    $this->actingAs($this->platformAdmin, 'platform')
        ->post(route('platform.seat-requests.approve', $request), ['review_note' => 'Welcome aboard'])
        ->assertRedirect(route('platform.seat-requests.index'));

    $request->refresh();
    $charge = BillingCharge::query()->with('lines')->sole();
    $line = $charge->lines->sole();

    expect($request->status)->toBe(SeatRequestStatus::Approved)
        ->and($request->reviewed_by)->toBe($this->platformAdmin->id)
        ->and($request->reviewed_at->toDateString())->toBe('2026-12-01')
        ->and($request->billing_charge_id)->toBe($charge->id)
        ->and($this->term->refresh()->seats)->toBe(5)
        ->and($this->term->initial_seats)->toBe(2)
        ->and($this->term->ends_on->toDateString())->toBe('2027-03-01')
        ->and($charge->type)->toBe(ChargeType::SeatIncrease)
        ->and($charge->amount)->toBe(8877)
        ->and($line->quantity)->toBe(3)
        ->and($line->unit_amount)->toBe(12000)
        ->and($line->calculation)->toMatchArray(['remaining_days' => 90, 'total_days' => 365, 'calculated_on' => '2026-12-01', 'annual_seat_price' => 12000]);

    // The tenant now has 4 available seats.
    expect(app(SeatGuard::class)->usage()->available())->toBe(4);
});

test('approval on the first day of the term charges the full annual seat price', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00'));
    $request = requestSeats(SeatRequestType::Increase, 2);

    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $request);

    expect(BillingCharge::query()->sole()->amount)->toBe(24000);
});

test('a request cannot be approved twice or charged twice', function () {
    $request = requestSeats(SeatRequestType::Increase, 1);
    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $request);

    expect(fn () => app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $request))
        ->toThrow(InvalidSubscriptionChange::class, 'already approved');

    $this->actingAs($this->platformAdmin, 'platform')
        ->post(route('platform.seat-requests.approve', $request))
        ->assertSessionHas('error');

    expect(BillingCharge::query()->count())->toBe(1)
        ->and($this->term->refresh()->seats)->toBe(3);
});

test('a rejected request leaves the entitlement and billing unchanged', function () {
    $request = requestSeats(SeatRequestType::Increase, 4);

    $this->actingAs($this->platformAdmin, 'platform')
        ->post(route('platform.seat-requests.reject', $request), ['review_note' => 'Please call us first'])
        ->assertRedirect();

    expect($request->refresh()->status)->toBe(SeatRequestStatus::Rejected)
        ->and($request->review_note)->toBe('Please call us first')
        ->and($this->term->refresh()->seats)->toBe(2)
        ->and(BillingCharge::query()->count())->toBe(0)
        ->and(fn () => app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $request))->toThrow(InvalidSubscriptionChange::class);
});

test('only one request can be pending, and it can be cancelled by the tenant', function () {
    $request = requestSeats(SeatRequestType::Increase, 1);

    expect(fn () => requestSeats(SeatRequestType::Reduction, 1))
        ->toThrow(InvalidSubscriptionChange::class, 'already pending');

    app(RequestSeatChange::class)->cancel($this->admin, $request->id);

    expect($request->refresh()->status)->toBe(SeatRequestStatus::Cancelled)
        ->and(requestSeats(SeatRequestType::Reduction, 1)->status)->toBe(SeatRequestStatus::Pending);
});

test('invalid seat requests are rejected', function (SeatRequestType $type, int $seats) {
    expect(fn () => requestSeats($type, $seats))->toThrow(InvalidSubscriptionChange::class);
    expect(SeatChangeRequest::query()->count())->toBe(0);
})->with([
    'zero additional seats' => [SeatRequestType::Increase, 0],
    'negative additional seats' => [SeatRequestType::Increase, -3],
    'above the seat limit' => [SeatRequestType::Increase, 100000],
    'reduction not lower than current seats' => [SeatRequestType::Reduction, 2],
    'reduction to zero' => [SeatRequestType::Reduction, 0],
]);

test('employees without the billing permission cannot see billing or request seats', function () {
    $employee = userWithPermissions([TenantPermission::EmployeesView]);

    $this->actingAs($employee, 'web')->get($this->tenant->url('subscription'))->assertForbidden();

    expect(fn () => app(RequestSeatChange::class)->handle($employee, SeatRequestType::Increase, 1))
        ->toThrow(AuthorizationException::class);

    $viewer = userWithPermissions([TenantPermission::BillingView]);
    $this->actingAs($viewer, 'web')->get($this->tenant->url('subscription'))->assertOk()->assertDontSee('Request additional seats');
});

test('a tenant cannot cancel or see another tenant\'s requests', function () {
    $globex = provisionTenant('globex', subscribed: false)->tenant;
    subscribeTenant($globex, seats: 5);
    $globexRequest = $globex->run(fn () => app(RequestSeatChange::class)->handle(tenantAdminUser(), SeatRequestType::Increase, 2));

    tenancy()->initialize($this->tenant);

    expect(fn () => app(RequestSeatChange::class)->cancel($this->admin, $globexRequest->id))
        ->toThrow(InvalidSubscriptionChange::class);

    Livewire::actingAs($this->admin)
        ->test('pages::subscription.index')
        ->assertDontSee('admin@globex.test')
        ->call('cancelRequest', $globexRequest->id);

    expect($globexRequest->refresh()->status)->toBe(SeatRequestStatus::Pending)
        ->and($globexRequest->tenant_id)->toBe($globex->id);
});

test('a reduction is scheduled for the next renewal and changes nothing now', function () {
    $request = requestSeats(SeatRequestType::Reduction, 1);

    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $request);

    expect($request->refresh()->status)->toBe(SeatRequestStatus::Approved)
        ->and($this->term->refresh()->seats)->toBe(2)
        ->and(BillingCharge::query()->count())->toBe(0)
        ->and(SubscriptionTerm::query()->count())->toBe(1);

    // The current term's purchased seats still apply.
    app(CreateEmployee::class)->handle($this->admin, ['name' => 'Bob', 'email' => 'bob@acme.test']);
    expect(app(SeatGuard::class)->usage()->used)->toBe(2);
});

test('a later approved reduction supersedes an earlier one', function () {
    $first = requestSeats(SeatRequestType::Reduction, 1);
    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $first);
    $second = requestSeats(SeatRequestType::Increase, 3);
    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $second);
    $third = requestSeats(SeatRequestType::Reduction, 4);
    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $third);

    expect($first->refresh()->status)->toBe(SeatRequestStatus::Superseded)
        ->and($third->refresh()->status)->toBe(SeatRequestStatus::Approved);
});

test('an approved increase supersedes a reduction scheduled earlier', function () {
    $reduction = requestSeats(SeatRequestType::Reduction, 1);
    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $reduction);
    $increase = requestSeats(SeatRequestType::Increase, 3);
    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $increase); // the term now has 5 seats

    expect($reduction->refresh()->status)->toBe(SeatRequestStatus::Superseded)
        ->and(PlatformAuditLog::query()->where('action', PlatformAuditAction::SeatRequestApproved)->latest('id')->first()->new_values['superseded_reductions'])
        ->toBe([$reduction->id]);

    // The renewal proposes the seats that were just paid for, not the old reduction.
    $this->travelTo(CarbonImmutable::parse('2027-02-15'));
    $quote = app(RenewSubscription::class)->quote($this->tenant);

    expect($quote->scheduledReduction)->toBeNull()
        ->and($quote->seats)->toBe(5);
});

/**
 * Renew the subscription early (inside the renewal window): the next term starts 2027-03-01.
 */
function renewEarly(int $seats): SubscriptionTerm
{
    test()->travelTo(CarbonImmutable::parse('2027-02-15 10:00:00'));

    return app(RenewSubscription::class)->handle(
        test()->platformAdmin, test()->tenant, SubscriptionPlan::query()->findOrFail(test()->term->subscription_plan_id), $seats, false,
    );
}

test('a reduction cannot be requested once the next term is confirmed', function () {
    renewEarly(2);

    expect(fn () => requestSeats(SeatRequestType::Reduction, 1))
        ->toThrow(InvalidSubscriptionChange::class, 'Your renewal from 2027-03-01 is already confirmed with 2 seats.');

    Livewire::actingAs($this->admin)
        ->test('pages::subscription.index')
        ->assertSeeHtml('data-test="reduction-after-renewal"')
        ->assertDontSeeHtml('data-test="request-reduction-button"');
});

test('a reduction requested before an early renewal cannot be approved after it', function () {
    $reduction = requestSeats(SeatRequestType::Reduction, 1);
    renewEarly(2);

    expect(fn () => app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $reduction))
        ->toThrow(InvalidSubscriptionChange::class, 'The next term is already confirmed');

    expect($reduction->refresh()->status)->toBe(SeatRequestStatus::Pending);
});

test('the seat maximum also applies to a confirmed later term', function () {
    config(['billing.max_seats' => 6]);
    renewEarly(4); // current term 2 seats, next term 4 seats

    expect(fn () => requestSeats(SeatRequestType::Increase, 3))
        ->toThrow(InvalidSubscriptionChange::class, 'Request between 1 and 2 additional seats.');

    $increase = requestSeats(SeatRequestType::Increase, 2);
    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $increase);

    expect($this->term->refresh()->seats)->toBe(4)
        ->and(SubscriptionTerm::query()->orderByDesc('sequence')->first()->seats)->toBe(6);
});

test('requests can only be made during an active term', function () {
    $this->travel(200)->days(); // past the 2027-03-01 renewal date

    expect(fn () => requestSeats(SeatRequestType::Increase, 1))
        ->toThrow(InvalidSubscriptionChange::class, 'active subscription term');
});

test('an increase cannot be approved once the term has ended', function () {
    $request = requestSeats(SeatRequestType::Increase, 1);
    $this->travelTo(CarbonImmutable::parse('2027-03-01 00:00:01'));

    expect(fn () => app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $request))
        ->toThrow(InvalidSubscriptionChange::class, 'no active term');

    expect(BillingCharge::query()->count())->toBe(0);
});
