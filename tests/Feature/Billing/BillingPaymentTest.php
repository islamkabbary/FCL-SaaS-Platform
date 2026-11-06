<?php

use App\Actions\Billing\ActivateSubscription;
use App\Actions\Billing\RecordPayment;
use App\Enums\ChargeStatus;
use App\Enums\ChargeType;
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
    testPrice(1800);
    app(ActivateSubscription::class)->handle($this->platformAdmin, $this->tenant, 5, CarbonImmutable::today());
    $this->charge = BillingCharge::query()->where('type', ChargeType::Setup)->sole(); // 18.00 × 12 × 5 = 1,080.00 USD
});

function pay(string $amount, ?string $token = null): TestResponse
{
    return test()->actingAs(test()->platformAdmin, 'platform')->post(route('platform.charges.payments.store', test()->charge), [
        'amount' => $amount,
        'paid_on' => '2026-05-10',
        'reference' => 'TRX-1001',
        'submission_token' => $token ?? (string) Str::uuid(),
    ]);
}

test('platform admins record partial and full payments with correct balances', function () {
    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.charges.show', $this->charge))
        ->assertOk()
        ->assertSee('1,080.00 USD')
        ->assertSee('Record a payment');

    pay('600.00')->assertRedirect(route('platform.charges.show', $this->charge))->assertSessionHasNoErrors();

    expect($this->charge->balance())->toBe(48000)
        ->and($this->charge->status())->toBe(ChargeStatus::PartiallyPaid);

    pay('480')->assertSessionHasNoErrors();

    expect($this->charge->balance())->toBe(0)
        ->and($this->charge->status())->toBe(ChargeStatus::Paid)
        ->and(BillingPayment::query()->sum('amount'))->toEqual(108000);

    $payment = BillingPayment::query()->first();
    expect($payment->recorded_by)->toBe($this->platformAdmin->id)
        ->and($payment->reference)->toBe('TRX-1001')
        ->and($payment->paid_on->toDateString())->toBe('2026-05-10')
        ->and($payment->currency)->toBe('USD');

    $entry = PlatformAuditLog::query()->where('action', PlatformAuditAction::PaymentRecorded)->orderBy('id')->first();
    expect($entry->platform_admin_id)->toBe($this->platformAdmin->id)
        ->and($entry->new_values)->toMatchArray(['amount' => 60000, 'balance' => 48000]);

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.charges.show', $this->charge))
        ->assertDontSee('Record a payment');
});

test('payments cannot exceed the balance, be zero, be in the future or have too many decimals', function (array $input, string $error) {
    test()->actingAs($this->platformAdmin, 'platform')->post(route('platform.charges.payments.store', $this->charge), [
        'amount' => '100',
        'paid_on' => '2026-05-10',
        'submission_token' => (string) Str::uuid(),
        ...$input,
    ])->assertSessionHasErrors($error);

    expect(BillingPayment::query()->count())->toBe(0);
})->with([
    'more than the balance' => [['amount' => '1080.01'], 'amount'],
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
        ->and($this->charge->balance())->toBe(98000);
});

test('a submission token used for one charge is refused for another', function () {
    $token = (string) Str::uuid();
    pay('100', $token)->assertSessionHasNoErrors();

    $other = BillingCharge::query()->create([
        ...$this->charge->only(['tenant_id', 'tenant_subscription_id', 'subscription_term_id', 'type', 'currency', 'subtotal', 'amount', 'description']),
        'idempotency_key' => 'other-charge',
        'issued_on' => '2026-05-10',
    ]);

    expect(fn () => app(RecordPayment::class)->handle($this->platformAdmin, $other, 10000, CarbonImmutable::today(), null, null, $token))
        ->toThrow(InvalidSubscriptionChange::class, 'This payment form was already used for another charge.');

    expect(BillingPayment::query()->where('billing_charge_id', $other->id)->count())->toBe(0);
});

test('concurrent payments cannot overpay a charge', function () {
    app(RecordPayment::class)->handle($this->platformAdmin, $this->charge, 100000, CarbonImmutable::today(), null, null, (string) Str::uuid());

    expect(fn () => app(RecordPayment::class)->handle($this->platformAdmin, $this->charge, 20000, CarbonImmutable::today(), null, null, (string) Str::uuid()))
        ->toThrow(InvalidSubscriptionChange::class, 'exceeds the outstanding balance of 80.00 USD');
});

test('charges, lines and payments can never be changed or deleted, apart from the discount', function () {
    $payment = app(RecordPayment::class)->handle($this->platformAdmin, $this->charge, 100, CarbonImmutable::today(), null, null, (string) Str::uuid());
    $line = BillingChargeLine::query()->first();

    expect(fn () => $this->charge->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $this->charge->update(['subtotal' => 1, 'amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $this->charge->update(['description' => 'Rewritten']))->toThrow(LogicException::class)
        ->and(fn () => $this->charge->delete())->toThrow(LogicException::class)
        ->and(fn () => BillingCharge::query()->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $line->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $payment->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $payment->delete())->toThrow(LogicException::class)
        ->and(fn () => SubscriptionTerm::query()->first()->update(['seat_price' => 1]))->toThrow(LogicException::class)
        ->and($this->charge->refresh()->amount)->toBe(108000);
});

test('the same charge event can never be created twice', function () {
    expect(fn () => BillingCharge::query()->create([
        ...$this->charge->only(['tenant_id', 'tenant_subscription_id', 'subscription_term_id', 'type', 'currency', 'subtotal', 'amount', 'description', 'issued_on']),
        'idempotency_key' => $this->charge->idempotency_key,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('tenant admins see their read-only billing history and cannot record payments', function () {
    app(RecordPayment::class)->handle($this->platformAdmin, $this->charge, 60000, CarbonImmutable::parse('2026-05-10'), 'TRX', null, (string) Str::uuid());
    tenancy()->initialize($this->tenant);
    $admin = tenantAdminUser();

    Livewire::actingAs($admin)
        ->test('pages::subscription.index')
        ->assertSee('Setup fee 2026-05-10 – 2027-05-09')
        ->assertSee('Monthly subscription 2026-05-10 – 2026-06-09')
        ->assertSee('1,080.00 USD')
        ->assertSee('Partially paid')
        ->assertSee('Payment May 10, 2026: 600.00 USD')
        ->assertDontSee('Record a payment');

    // No tenant route can record payments: the platform area does not exist on a tenant subdomain.
    $this->actingAs($admin, 'web')
        ->post($this->tenant->url("admin/charges/{$this->charge->id}/payments"), ['amount' => '1000', 'paid_on' => '2026-05-10', 'submission_token' => (string) Str::uuid()])
        ->assertNotFound();

    expect(BillingPayment::query()->count())->toBe(1);
});

test('company A cannot see company B subscription or charges', function () {
    $globex = provisionTenant('globex', subscribed: false)->tenant;
    testPrice(7777);
    app(ActivateSubscription::class)->handle($this->platformAdmin, $globex, 2, CarbonImmutable::today());

    tenancy()->initialize($this->tenant);

    Livewire::actingAs(tenantAdminUser())
        ->test('pages::subscription.index')
        ->assertSee('1,080.00 USD')
        ->assertDontSee('1,866.48 USD')
        ->assertDontSee('155.54 USD');
});

test('the billing list can show outstanding charges only', function () {
    foreach (BillingCharge::query()->get() as $charge) {
        app(RecordPayment::class)->handle($this->platformAdmin, $charge, $charge->amount, CarbonImmutable::today(), null, null, (string) Str::uuid());
    }

    $other = provisionTenant('globex', subscribed: false)->tenant;
    app(ActivateSubscription::class)->handle($this->platformAdmin, $other, 1, CarbonImmutable::today());

    $response = $this->actingAs($this->platformAdmin, 'platform')->get(route('platform.charges.index', ['outstanding' => 1]))->assertOk();

    expect($response->viewData('charges')->pluck('tenant_id')->unique()->values()->all())->toBe([$other->id]);

    // 18.00 × 12 setup fee plus the first month of 18.00.
    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.dashboard'))
        ->assertViewHas('billing', fn (array $billing) => $billing['outstanding'] === ['USD' => 23400] && $billing['overdue'] === 0);
});
