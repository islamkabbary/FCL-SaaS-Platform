<?php

use App\Actions\Billing\ActivateSubscription;
use App\Actions\Platform\SuspendTenant;
use App\Enums\ChargeType;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Exceptions\InvalidSubscriptionChange;
use App\Models\BillingCharge;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SubscriptionTerm;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->admin = PlatformAdmin::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00'));
});

function employeeLogin(Tenant $tenant, string $email = 'employee@acme.test'): void
{
    // actingAs(..., 'platform') earlier in a test changes the default guard of the shared
    // auth manager; a real tenant request always starts with the "web" guard.
    auth()->shouldUse('web');
    $tenant->run(fn () => User::factory()->create(['email' => $email]));
    test()->post($tenant->url('login'), ['email' => $email, 'password' => 'password'])->assertSessionHasNoErrors();
}

test('the existing-tenant migration keeps tenants created before subscriptions as legacy', function () {
    $legacy = provisionTenant('legacy', subscribed: false)->tenant;
    // What the migration does to tenants that existed before it ran.
    DB::table('tenants')->where('id', $legacy->id)->update(['subscription_required' => false]);
    $legacy->refresh();

    expect($legacy->subscriptionStatus())->toBe(SubscriptionStatus::Legacy)
        ->and($legacy->seatLimit())->toBeNull();

    employeeLogin($legacy, 'employee@legacy.test');
    $this->get($legacy->url('dashboard'))->assertOk();
});

test('a new tenant requires a subscription: users can sign in but not use the application', function () {
    $tenant = provisionTenant('acme', subscribed: false)->tenant;

    expect($tenant->refresh()->subscription_required)->toBeTrue()
        ->and($tenant->subscriptionStatus())->toBe(SubscriptionStatus::None);

    employeeLogin($tenant);

    $this->get($tenant->url('dashboard'))->assertRedirect($tenant->url('subscription-required'));
    $this->get($tenant->url('employees'))->assertRedirect($tenant->url('subscription-required'));
    $this->get($tenant->url('subscription-required'))->assertOk()->assertSee('does not have an active subscription yet');
    $this->get($tenant->url('settings/profile'))->assertOk();
});

test('platform admins activate a subscription after previewing the annual charge', function () {
    $tenant = provisionTenant('acme', subscribed: false)->tenant;
    $plan = testPlan(['name' => 'Standard']);

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.subscriptions.activate', [$tenant, 'plan_id' => $plan->id, 'seats' => 10, 'starts_on' => '2026-10-20']))
        ->assertOk()
        ->assertSee('1,000.00 USD + 10 × 120.00 USD = 2,200.00 USD')
        ->assertSee('2026-10-20 to 2027-10-19 (inclusive), renews on 2027-10-20');

    $this->actingAs($this->admin, 'platform')
        ->post(route('platform.subscriptions.activate.store', $tenant), ['plan_id' => $plan->id, 'seats' => 10, 'starts_on' => '2026-10-20'])
        ->assertRedirect(route('platform.subscriptions.show', $tenant))
        ->assertSessionHasNoErrors();

    $term = SubscriptionTerm::query()->sole();
    $charge = BillingCharge::query()->with('lines')->sole();

    expect($tenant->subscriptionStatus())->toBe(SubscriptionStatus::Active)
        ->and($term->seats)->toBe(10)
        ->and($term->starts_on->toDateString())->toBe('2026-10-20')
        ->and($term->ends_on->toDateString())->toBe('2027-10-20')
        ->and($term->annual_base_fee)->toBe(100000)
        ->and($term->annual_seat_price)->toBe(12000)
        ->and($charge->type)->toBe(ChargeType::Initial)
        ->and($charge->amount)->toBe(220000)
        ->and($charge->currency)->toBe('USD')
        ->and($charge->lines->pluck('amount')->all())->toBe([100000, 120000])
        ->and(PlatformAuditLog::query()->orderBy('id')->pluck('action')->map->value->all())->toBe(['subscription.activated', 'billing.charge_created'])
        ->and(PlatformAuditLog::query()->first()->platform_admin_id)->toBe($this->admin->id);

    employeeLogin($tenant);
    $this->get($tenant->url('dashboard'))->assertOk();
});

test('activating a legacy tenant transitions it to subscription licensing', function () {
    $tenant = provisionTenant('acme', subscribed: false)->tenant;
    DB::table('tenants')->where('id', $tenant->id)->update(['subscription_required' => false]);

    app(ActivateSubscription::class)->handle($this->admin, $tenant->refresh(), testPlan(), 5, CarbonImmutable::today());

    expect($tenant->refresh()->subscription_required)->toBeTrue()
        ->and($tenant->seatLimit())->toBe(5);
});

test('invalid activations are rejected', function (Closure $scenario, string $message) {
    $tenant = provisionTenant('acme', subscribed: false)->tenant;

    expect(fn () => $scenario($tenant, $this->admin))->toThrow(InvalidSubscriptionChange::class, $message);
    expect(TenantSubscription::query()->count())->toBeLessThanOrEqual(1)
        ->and(BillingCharge::query()->count())->toBeLessThanOrEqual(1);
})->with([
    'start date in the future' => [fn ($tenant, $admin) => app(ActivateSubscription::class)->handle($admin, $tenant, testPlan(), 5, CarbonImmutable::parse('2026-11-01')), 'cannot be in the future'],
    'term already over' => [fn ($tenant, $admin) => app(ActivateSubscription::class)->handle($admin, $tenant, testPlan(), 5, CarbonImmutable::parse('2025-09-01')), 'would already have ended'],
    'fewer seats than active users' => [function ($tenant, $admin) {
        $tenant->run(fn () => User::factory()->count(3)->create());
        app(ActivateSubscription::class)->handle($admin, $tenant, testPlan(), 2, CarbonImmutable::today());
    }, 'At least 4 seats are needed'],
    'archived plan' => [fn ($tenant, $admin) => app(ActivateSubscription::class)->handle($admin, $tenant, testPlan(['is_active' => false]), 5, CarbonImmutable::today()), 'is archived'],
    'second activation' => [function ($tenant, $admin) {
        app(ActivateSubscription::class)->handle($admin, $tenant, testPlan(), 5, CarbonImmutable::today());
        app(ActivateSubscription::class)->handle($admin, $tenant, testPlan(), 5, CarbonImmutable::today());
    }, 'already has a subscription'],
]);

test('an unprovisioned tenant cannot be subscribed', function () {
    $tenant = centralTenant('pending', TenantStatus::Pending);

    expect(fn () => app(ActivateSubscription::class)->handle($this->admin, $tenant, testPlan(), 5, CarbonImmutable::today()))
        ->toThrow(InvalidSubscriptionChange::class, 'Only a ready');
});

test('an expired subscription restricts the application, including existing sessions and livewire requests', function () {
    $tenant = provisionTenant('acme', subscribed: false)->tenant;
    subscribeTenant($tenant, seats: 10, daysAgo: 360);

    $this->post($tenant->url('login'), ['email' => 'admin@acme.test', 'password' => 'password'])->assertSessionHasErrors();
    $tenant->run(fn () => tenantAdminUser()->forceFill(['password' => 'password'])->save());
    $this->post($tenant->url('login'), ['email' => 'admin@acme.test', 'password' => 'password'])->assertSessionHasNoErrors();

    $html = $this->get($tenant->url('employees'))->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
    $livewire = function () use ($tenant, $snapshot) {
        $response = $this->withHeaders(['X-Livewire' => '1'])
            ->postJson($tenant->url(route('livewire.update', absolute: false)), ['components' => [['snapshot' => $snapshot, 'updates' => ['search' => 'x'], 'calls' => []]]]);

        // Headers set with withHeaders() persist; later requests must be plain page requests.
        $this->flushHeaders();

        return $response;
    };

    $livewire()->assertOk();

    // The term ends 2026-10-25 (exclusive); the next day it has expired.
    $this->travelTo(CarbonImmutable::parse('2026-10-25 08:00:00'));
    auth()->forgetGuards();

    expect($tenant->subscriptionStatus())->toBe(SubscriptionStatus::Expired);
    $this->get($tenant->url('employees'))->assertRedirect($tenant->url('subscription-required'));
    $livewire()->assertForbidden();
    $this->get($tenant->url('subscription'))->assertOk()->assertSee('Your annual subscription has expired');
    $this->get($tenant->url('subscription-required'))->assertOk()->assertSee('has expired');
});

test('the last covered day of a term still allows access', function () {
    $tenant = provisionTenant('acme', subscribed: false)->tenant;
    $term = subscribeTenant($tenant, daysAgo: 364); // 2025-10-21 → ends 2026-10-21

    expect($term->lastDay()->toDateString())->toBe('2026-10-20')
        ->and($tenant->subscriptionStatus())->toBe(SubscriptionStatus::Active);

    $this->travelTo(CarbonImmutable::parse('2026-10-21 00:00:01'));
    expect($tenant->subscriptionStatus())->toBe(SubscriptionStatus::Expired);
});

test('manual suspension stays independent of the subscription', function () {
    $tenant = provisionTenant('acme', subscribed: false)->tenant;
    app(SuspendTenant::class)->handle($this->admin, $tenant, 'Contract under review');

    app(ActivateSubscription::class)->handle($this->admin, $tenant->refresh(), testPlan(), 5, CarbonImmutable::today());
    $tenant->refresh();

    expect($tenant->isSuspended())->toBeTrue()
        ->and($tenant->status)->toBe(TenantStatus::Ready)
        ->and($tenant->subscriptionStatus())->toBe(SubscriptionStatus::Active);

    $this->get($tenant->url('login'))->assertForbidden();
});

test('suspension and provisioning fields are never changed by billing', function () {
    $tenant = provisionTenant('acme', subscribed: false)->tenant;
    $before = $tenant->refresh()->only(['status', 'suspended_at', 'suspension_reason', 'provisioned_at']);

    app(ActivateSubscription::class)->handle($this->admin, $tenant, testPlan(), 5, CarbonImmutable::today());

    expect($tenant->refresh()->only(['status', 'suspended_at', 'suspension_reason', 'provisioned_at']))->toEqual($before);
});

test('the platform subscription pages show status, seats and outstanding amounts', function () {
    $tenant = provisionTenant('acme', subscribed: false)->tenant;
    app(ActivateSubscription::class)->handle($this->admin, $tenant, testPlan(), 4, CarbonImmutable::today());

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.subscriptions.show', $tenant))
        ->assertOk()
        ->assertSee('Subscription: Active')
        ->assertSeeInOrder(['Purchased seats', '4', 'Active users', '1', 'Outstanding', '1,480.00 USD'])
        ->assertSee('Oct 20, 2027');

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.subscriptions.index', ['status' => 'active']))
        ->assertOk()
        ->assertSee('Acme Freight');

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.dashboard'))
        ->assertOk()
        ->assertViewHas('billing', fn (array $billing) => $billing['active'] === 1 && $billing['outstanding'] === ['USD' => 148000]);
});

test('existing tenant admins receive the billing permissions from tenants:sync-authorization', function () {
    $tenant = provisionTenant('acme')->tenant;
    $tenant->run(function () {
        Role::findByName('Tenant Admin', 'web')->revokePermissionTo(['billing.view', 'billing.manage']);
        expect(tenantAdminUser()->can('billing.view'))->toBeFalse();
    });

    $this->artisan('tenants:sync-authorization')->assertSuccessful();

    $tenant->run(fn () => expect(tenantAdminUser()->fresh()->can('billing.view'))->toBeTrue()
        ->and(tenantAdminUser()->fresh()->can('billing.manage'))->toBeTrue());
});
