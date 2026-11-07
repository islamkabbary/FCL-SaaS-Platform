<?php

use App\Actions\Billing\ActivateSubscription;
use App\Actions\Billing\SaveBillingSettings;
use App\Enums\PlatformAuditAction;
use App\Models\BillingSetting;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SubscriptionTerm;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->admin = PlatformAdmin::factory()->create();
});

test('platform admins set the price per user in minor units', function () {
    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.billing-settings.edit'))
        ->assertOk()
        ->assertSee('KWD (3 decimals)');

    $this->actingAs($this->admin, 'platform')
        ->put(route('platform.billing-settings.update'), ['currency' => 'kwd', 'seat_price' => '5.250'])
        ->assertRedirect(route('platform.billing-settings.edit'))
        ->assertSessionHasNoErrors();

    $setting = BillingSetting::query()->sole();

    expect($setting->currency)->toBe('KWD')
        ->and($setting->seat_price)->toBe(5250)
        ->and($setting->updated_by)->toBe($this->admin->id)
        ->and(PlatformAuditLog::query()->sole()->action)->toBe(PlatformAuditAction::BillingSettingsUpdated);

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.billing-settings.edit'))
        ->assertSee('value="5.250"', false);
});

test('the price input is validated server-side', function (array $input, string $field) {
    $this->actingAs($this->admin, 'platform')
        ->put(route('platform.billing-settings.update'), ['currency' => 'USD', 'seat_price' => '18', ...$input])
        ->assertSessionHasErrors($field);

    expect(BillingSetting::query()->count())->toBe(0);
})->with([
    'unsupported currency' => [['currency' => 'XYZ'], 'currency'],
    'missing currency' => [['currency' => ''], 'currency'],
    'negative price' => [['seat_price' => '-10'], 'seat_price'],
    'too many decimals for the currency' => [['seat_price' => '10.123'], 'seat_price'],
    'not a number' => [['seat_price' => 'ten'], 'seat_price'],
    'too large' => [['seat_price' => '999999999999'], 'seat_price'],
]);

test('a new price applies to new subscriptions and never rewrites existing terms', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-01 09:00:00'));
    testPrice(1800);
    $existing = subscribeTenant(provisionTenant('acme', subscribed: false)->tenant, seats: 5);

    $this->actingAs($this->admin, 'platform')
        ->put(route('platform.billing-settings.update'), ['currency' => 'USD', 'seat_price' => '20'])
        ->assertSessionHasNoErrors();

    $globex = provisionTenant('globex', subscribed: false)->tenant;
    app(ActivateSubscription::class)->handle($this->admin, $globex, 3, CarbonImmutable::today());

    expect($existing->refresh()->seat_price)->toBe(1800)
        ->and(SubscriptionTerm::query()->where('tenant_id', $globex->id)->sole()->seat_price)->toBe(2000);

    $entry = PlatformAuditLog::query()->where('action', PlatformAuditAction::BillingSettingsUpdated)->sole();
    expect($entry->old_values)->toMatchArray(['seat_price' => 1800])
        ->and($entry->new_values)->toMatchArray(['seat_price' => 2000]);
});

test('saving an unchanged price records nothing', function () {
    testPrice(1800);

    app(SaveBillingSettings::class)->handle($this->admin, 'USD', 1800);

    expect(PlatformAuditLog::query()->count())->toBe(0);
});

test('the price cannot be reached or changed from a tenant subdomain', function () {
    $tenant = actingInTenant();

    $this->actingAs(superAdminUser(), 'web');
    $this->get($tenant->url('admin/billing-settings'))->assertNotFound();
    $this->put($tenant->url('admin/billing-settings'), ['currency' => 'USD', 'seat_price' => '0'])->assertNotFound();

    expect(BillingSetting::query()->sole()->seat_price)->toBe(1800);
});

test('a platform admin without the billing gate is refused by the action', function () {
    Gate::define('manage-billing', fn (): bool => false);

    expect(fn () => app(SaveBillingSettings::class)->handle($this->admin, 'USD', 1800))->toThrow(AuthorizationException::class);
    expect(BillingSetting::query()->count())->toBe(0);
});

test('subscription and billing administration is reserved to platform admins', function (string $method, string $route, array $parameters) {
    $this->{$method}(route($route, $parameters))->assertRedirect(route('platform.login'));
})->with([
    ['get', 'platform.billing-settings.edit', []],
    ['put', 'platform.billing-settings.update', []],
    ['get', 'platform.subscriptions.index', []],
    ['get', 'platform.seat-requests.index', []],
    ['get', 'platform.charges.index', []],
    ['post', 'platform.charges.discount.store', ['charge' => 1]],
]);
