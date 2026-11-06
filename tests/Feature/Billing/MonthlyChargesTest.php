<?php

use App\Actions\Billing\ActivateSubscription;
use App\Actions\Billing\IssueMonthlyCharges;
use App\Actions\Billing\RequestSeatChange;
use App\Actions\Billing\ReviewSeatChangeRequest;
use App\Enums\ChargeType;
use App\Enums\SeatRequestType;
use App\Models\BillingCharge;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01 09:00:00'));
    $this->platformAdmin = PlatformAdmin::factory()->create();
    testPrice(1800);
    $this->tenant = provisionTenant('acme', subscribed: false)->tenant;

    // 100 users from 1 January: a setup fee of 21,600.00, then 1,800.00 each month.
    app(ActivateSubscription::class)->handle($this->platformAdmin, $this->tenant, 100, CarbonImmutable::today());
});

/**
 * @return list<int>
 */
function monthlyAmounts(): array
{
    return BillingCharge::query()->where('type', ChargeType::Monthly)->orderBy('period_index')->pluck('amount')->all();
}

test('each month is billed on its first day by the scheduled command, once', function () {
    expect(monthlyAmounts())->toBe([180_000]);

    $this->travelTo(CarbonImmutable::parse('2026-01-31 01:00:00'));
    $this->artisan('billing:issue-monthly-charges')->expectsOutputToContain('0 monthly charges issued.')->assertSuccessful();

    $this->travelTo(CarbonImmutable::parse('2026-02-01 01:00:00'));
    $this->artisan('billing:issue-monthly-charges')->expectsOutputToContain('1 monthly charges issued.')->assertSuccessful();
    $this->artisan('billing:issue-monthly-charges')->expectsOutputToContain('0 monthly charges issued.')->assertSuccessful();

    $february = BillingCharge::query()->where('type', ChargeType::Monthly)->where('period_index', 1)->sole();

    expect($february->amount)->toBe(180_000)
        ->and($february->period_starts_on->toDateString())->toBe('2026-02-01')
        ->and($february->period_ends_on->toDateString())->toBe('2026-03-01')
        ->and($february->issued_on->toDateString())->toBe('2026-02-01')
        ->and($february->created_by)->toBeNull()
        ->and(PlatformAuditLog::query()->latest('id')->first()->isSystemAction())->toBeTrue();
});

test('months missed while the scheduler was down are issued on the next run', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15'));

    expect(app(IssueMonthlyCharges::class)->handle())->toBe(3)
        ->and(BillingCharge::query()->where('type', ChargeType::Monthly)->orderBy('period_index')->pluck('period_index')->all())->toBe([0, 1, 2, 3]);
});

test('a term is billed for exactly twelve months and nothing after it ends', function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-01'));
    app(IssueMonthlyCharges::class)->handle();

    expect(monthlyAmounts())->toHaveCount(12)
        ->and(BillingCharge::query()->where('type', ChargeType::Monthly)->max('period_ends_on'))->toStartWith('2027-01-01');

    expect(app(IssueMonthlyCharges::class)->handle())->toBe(0);
});

test('users added during a month are billed monthly from the next month, after their setup fee', function () {
    // The owner's example: 20 users added in the seventh month pay 18 × 6 × 20, then 120 × 18 a month.
    $this->travelTo(CarbonImmutable::parse('2026-07-10'));
    app(IssueMonthlyCharges::class)->handle();

    tenancy()->initialize($this->tenant);
    $request = app(RequestSeatChange::class)->handle(tenantAdminUser(), SeatRequestType::Increase, 20);
    tenancy()->end();
    app(ReviewSeatChangeRequest::class)->approve($this->platformAdmin, $request);

    expect(BillingCharge::query()->where('type', ChargeType::SeatSetup)->sole()->amount)->toBe(216_000);

    $this->travelTo(CarbonImmutable::parse('2026-12-01'));
    app(IssueMonthlyCharges::class)->handle();

    expect(monthlyAmounts())->toBe([
        180_000, 180_000, 180_000, 180_000, 180_000, 180_000, 180_000, // January to July: 100 users
        216_000, 216_000, 216_000, 216_000, 216_000,                   // August to December: 120 users
    ]);
});

test('the monthly command is scheduled daily', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'billing:issue-monthly-charges'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 1 * * *');
});

test('unpaid charges are listed as overdue after the configured days', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-08'));

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.dashboard'))
        ->assertViewHas('billing', fn (array $billing): bool => $billing['overdue'] === 0);

    $this->travelTo(CarbonImmutable::parse('2026-01-09'));

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.dashboard'))
        ->assertViewHas('billing', fn (array $billing): bool => $billing['overdue'] === 2);

    $response = $this->actingAs($this->platformAdmin, 'platform')->get(route('platform.charges.index', ['overdue' => 1]))->assertOk();

    expect($response->viewData('charges')->total())->toBe(2);
});
