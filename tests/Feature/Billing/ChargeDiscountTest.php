<?php

use App\Actions\Billing\ActivateSubscription;
use App\Actions\Billing\ApplyChargeDiscount;
use App\Actions\Billing\RecordPayment;
use App\Enums\ChargeType;
use App\Enums\PlatformAuditAction;
use App\Exceptions\InvalidSubscriptionChange;
use App\Models\BillingCharge;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01 09:00:00'));
    $this->platformAdmin = PlatformAdmin::factory()->create();
    testPrice(1800);
    $this->tenant = provisionTenant('acme', subscribed: false)->tenant;

    // The owner's example: 100 users at 18.00 → a setup fee of 21,600.00 and 1,800.00 a month.
    app(ActivateSubscription::class)->handle($this->platformAdmin, $this->tenant, 100, CarbonImmutable::today());
    $this->setup = BillingCharge::query()->where('type', ChargeType::Setup)->sole();
    $this->monthly = BillingCharge::query()->where('type', ChargeType::Monthly)->sole();
});

function discount(BillingCharge $charge, string $percent)
{
    return test()->actingAs(test()->platformAdmin, 'platform')
        ->post(route('platform.charges.discount.store', $charge), ['discount_percent' => $percent, 'reason' => 'Launch offer']);
}

test('a percentage discount on the setup fee lowers the amount due and is audited', function () {
    expect($this->setup->amount)->toBe(2_160_000);

    discount($this->setup, '12.5')->assertRedirect(route('platform.charges.show', $this->setup))->assertSessionHasNoErrors();

    $this->setup->refresh();
    $entry = PlatformAuditLog::query()->where('action', PlatformAuditAction::ChargeDiscountApplied)->sole();

    expect($this->setup->subtotal)->toBe(2_160_000)
        ->and($this->setup->discount_basis_points)->toBe(1250)
        ->and($this->setup->discount_amount)->toBe(270_000)
        ->and($this->setup->amount)->toBe(1_890_000)
        ->and($this->setup->balance())->toBe(1_890_000)
        ->and($entry->old_values)->toMatchArray(['discount_basis_points' => 0, 'amount' => '21,600.00 USD'])
        ->and($entry->new_values)->toMatchArray(['discount_basis_points' => 1250, 'amount' => '18,900.00 USD'])
        ->and($entry->reason)->toBe('Launch offer');

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.charges.show', $this->setup))
        ->assertOk()
        ->assertSee('Discount (12.5%)')
        ->assertSee('−2,700.00 USD')
        ->assertSee('18,900.00 USD');

    tenancy()->initialize($this->tenant);
    Livewire::actingAs(tenantAdminUser())
        ->test('pages::subscription.index')
        ->assertSee('Discount (12.5%)')
        ->assertSee('18,900.00 USD');
});

test('any monthly charge can be discounted too, and the discount removed again', function () {
    discount($this->monthly, '10')->assertSessionHasNoErrors();
    expect($this->monthly->refresh()->amount)->toBe(162_000);

    discount($this->monthly, '0')->assertSessionHasNoErrors();
    expect($this->monthly->refresh()->amount)->toBe(180_000)
        ->and($this->monthly->discount_amount)->toBe(0);
});

test('the discount can no longer change once a payment is recorded', function () {
    discount($this->setup, '10');
    app(RecordPayment::class)->handle($this->platformAdmin, $this->setup->refresh(), 100, CarbonImmutable::today(), null, null, (string) Str::uuid());

    discount($this->setup, '50')->assertSessionHasErrors(['discount_percent' => 'A payment was already recorded for this charge, so its discount can no longer change.']);

    expect($this->setup->refresh()->discount_basis_points)->toBe(1000);

    $this->actingAs($this->platformAdmin, 'platform')
        ->get(route('platform.charges.show', $this->setup))
        ->assertDontSeeHtml('data-test="discount-form"');
});

test('payments are checked against the discounted amount', function () {
    discount($this->monthly, '50');

    expect(fn () => app(RecordPayment::class)->handle($this->platformAdmin, $this->monthly->refresh(), 180_000, CarbonImmutable::today(), null, null, (string) Str::uuid()))
        ->toThrow(InvalidSubscriptionChange::class, 'exceeds the outstanding balance of 900.00 USD');

    app(RecordPayment::class)->handle($this->platformAdmin, $this->monthly, 90_000, CarbonImmutable::today(), null, null, (string) Str::uuid());

    expect($this->monthly->refresh()->balance())->toBe(0);
});

test('the discount percentage is validated', function (string $percent) {
    discount($this->setup, $percent)->assertSessionHasErrors('discount_percent');

    expect($this->setup->refresh()->discount_basis_points)->toBe(0);
})->with([
    'above 100' => ['100.5'],
    'negative' => ['-10'],
    'too many decimals' => ['12.345'],
    'not a number' => ['ten'],
    'empty' => [''],
]);

test('the action refuses an out-of-range discount whatever the caller', function () {
    expect(fn () => app(ApplyChargeDiscount::class)->handle($this->platformAdmin, $this->setup, 10_001))
        ->toThrow(InvalidSubscriptionChange::class, 'between 0% and 100%');
});

test('a tenant user cannot discount a charge', function () {
    tenancy()->initialize($this->tenant);
    $this->actingAs(tenantAdminUser(), 'web')
        ->post($this->tenant->url("admin/charges/{$this->setup->id}/discount"), ['discount_percent' => '100'])
        ->assertNotFound();
    tenancy()->end();

    expect($this->setup->refresh()->discount_basis_points)->toBe(0);
});
