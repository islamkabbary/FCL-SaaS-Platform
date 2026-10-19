<?php

use App\Actions\Billing\ActivateSubscription;
use App\Actions\Billing\RecordPayment;
use App\Enums\ChargeStatus;
use App\Enums\PlatformAuditAction;
use App\Exceptions\InvalidSubscriptionChange;
use App\Models\BillingCharge;
use App\Models\BillingChargeLine;
use App\Models\BillingPayment;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SubscriptionTerm;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-10 09:00:00'));
    $this->platformAdmin = PlatformAdmin::factory()->create();
    $this->tenant = provisionTenant('acme', subscribed: false)->tenant;
    app(ActivateSubscription::class)->handle($this->platformAdmin, $this->tenant, testPlan(), 5, CarbonImmutable::today());
    $this->charge = BillingCharge::query()->sole(); // 1,600.00 USD
});

function pay(string $amount, ?string $token = null): TestResponse
{
    return test()->actingAs(test()->platformAdmin, 'platform')->post(route('platform.charges.payments.store', test()->charge), [
        'amount' => $amount,
        'paid_on' => '2026-05-09',
        'reference' => 'TRX-1001',
        'submission_token' => $token ?? (string) Str::uuid(),
    ]);
}

test('platform admins record partial and full payments with correct balances', function () {
    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.charges.show', $this->charge))
        ->assertOk()
        ->assertSee('1,600.00 USD')
        ->assertSee('Record a payment');

    pay('600.00')->assertRedirect(route('platform.charges.show', $this->charge))->assertSessionHasNoErrors();

    expect($this->charge->balance())->toBe(100000)
        ->and($this->charge->status())->toBe(ChargeStatus::PartiallyPaid);

    pay('1000')->assertSessionHasNoErrors();

    expect($this->charge->balance())->toBe(0)
        ->and($this->charge->status())->toBe(ChargeStatus::Paid)
        ->and(BillingPayment::query()->sum('amount'))->toEqual(160000);

    $payment = BillingPayment::query()->first();
    expect($payment->recorded_by)->toBe($this->platformAdmin->id)
        ->and($payment->reference)->toBe('TRX-1001')
        ->and($payment->paid_on->toDateString())->toBe('2026-05-09')
        ->and($payment->currency)->toBe('USD');

    $entry = PlatformAuditLog::query()->where('action', PlatformAuditAction::PaymentRecorded)->orderBy('id')->first();
    expect($entry->platform_admin_id)->toBe($this->platformAdmin->id)
        ->and($entry->new_values)->toMatchArray(['amount' => 60000, 'balance' => 100000]);

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.charges.show', $this->charge))
        ->assertDontSee('Record a payment');
});

test('payments cannot exceed the balance, be zero, be in the future or have too many decimals', function (array $input, string $error) {
    test()->actingAs($this->platformAdmin, 'platform')->post(route('platform.charges.payments.store', $this->charge), [
        'amount' => '100',
        'paid_on' => '2026-05-09',
        'submission_token' => (string) Str::uuid(),
        ...$input,
    ])->assertSessionHasErrors($error);

    expect(BillingPayment::query()->count())->toBe(0);
})->with([
    'more than the balance' => [['amount' => '1600.01'], 'amount'],
    'zero' => [['amount' => '0'], 'amount'],
    'too many decimals' => [['amount' => '10.005'], 'amount'],
    'negative' => [['amount' => '-5'], 'amount'],
    'future date' => [['paid_on' => '2026-05-11'], 'paid_on'],
    'missing token' => [['submission_token' => ''], 'submission_token'],
]);

test('a double-submitted payment form records one payment', function () {
    $token = (string) Str::uuid();

    pay('100', $token)->assertSessionHasNoErrors();
    pay('100', $token)->assertSessionHasNoErrors();

    expect(BillingPayment::query()->count())->toBe(1)
        ->and($this->charge->balance())->toBe(150000);
});

test('concurrent payments cannot overpay a charge', function () {
    app(RecordPayment::class)->handle($this->platformAdmin, $this->charge, 150000, CarbonImmutable::today(), null, null, (string) Str::uuid());

    expect(fn () => app(RecordPayment::class)->handle($this->platformAdmin, $this->charge, 20000, CarbonImmutable::today(), null, null, (string) Str::uuid()))
        ->toThrow(InvalidSubscriptionChange::class, 'exceeds the outstanding balance of 100.00 USD');
});

test('charges, lines and payments can never be changed or deleted', function () {
    $payment = app(RecordPayment::class)->handle($this->platformAdmin, $this->charge, 100, CarbonImmutable::today(), null, null, (string) Str::uuid());
    $line = BillingChargeLine::query()->first();

    expect(fn () => $this->charge->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $this->charge->delete())->toThrow(LogicException::class)
        ->and(fn () => $line->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $payment->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $payment->delete())->toThrow(LogicException::class)
        ->and(fn () => SubscriptionTerm::query()->first()->update(['annual_seat_price' => 1]))->toThrow(LogicException::class)
        ->and($this->charge->refresh()->amount)->toBe(160000);
});

test('the same charge event can never be created twice', function () {
    expect(fn () => BillingCharge::query()->create([
        ...$this->charge->only(['tenant_id', 'tenant_subscription_id', 'subscription_term_id', 'type', 'currency', 'amount', 'description', 'issued_on']),
        'idempotency_key' => $this->charge->idempotency_key,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('tenant admins see their read-only billing history and cannot record payments', function () {
    app(RecordPayment::class)->handle($this->platformAdmin, $this->charge, 60000, CarbonImmutable::parse('2026-05-09'), 'TRX', null, (string) Str::uuid());
    tenancy()->initialize($this->tenant);
    $admin = tenantAdminUser();

    Livewire::actingAs($admin)
        ->test('pages::subscription.index')
        ->assertSee('Annual subscription 2026-05-10 – 2027-05-09')
        ->assertSee('1,600.00 USD')
        ->assertSee('Partially paid')
        ->assertSee('Payment May 9, 2026: 600.00 USD')
        ->assertDontSee('Record a payment');

    // No tenant route can record payments: the platform area does not exist on a tenant subdomain.
    $this->actingAs($admin, 'web')
        ->post($this->tenant->url("admin/charges/{$this->charge->id}/payments"), ['amount' => '1000', 'paid_on' => '2026-05-09', 'submission_token' => (string) Str::uuid()])
        ->assertNotFound();

    expect(BillingPayment::query()->count())->toBe(1);
});

test('company A cannot see company B subscription or charges', function () {
    $globex = provisionTenant('globex', subscribed: false)->tenant;
    app(ActivateSubscription::class)->handle($this->platformAdmin, $globex, testPlan(['annual_base_fee' => 777700]), 2, CarbonImmutable::today());

    tenancy()->initialize($this->tenant);

    Livewire::actingAs(tenantAdminUser())
        ->test('pages::subscription.index')
        ->assertSee('1,600.00 USD')
        ->assertDontSee('7,777.00 USD')
        ->assertDontSee('8,017.00 USD');
});

test('the billing list can show outstanding charges only', function () {
    app(RecordPayment::class)->handle($this->platformAdmin, $this->charge, 160000, CarbonImmutable::today(), null, null, (string) Str::uuid());
    $other = provisionTenant('globex', subscribed: false)->tenant;
    app(ActivateSubscription::class)->handle($this->platformAdmin, $other, testPlan(), 1, CarbonImmutable::today());

    $response = $this->actingAs($this->platformAdmin, 'platform')->get(route('platform.charges.index', ['outstanding' => 1]))->assertOk();

    expect($response->viewData('charges')->pluck('tenant_id')->all())->toBe([$other->id]);

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.dashboard'))
        ->assertViewHas('billing', fn (array $billing) => $billing['outstanding'] === ['USD' => 112000]);
});
