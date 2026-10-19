<?php

use App\Actions\Tenancy\ProvisioningResult;
use App\Actions\Tenancy\ProvisionTenant;
use App\Billing\Pricing;
use App\Enums\SubscriptionTermKind;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTerm;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Create and fully provision a tenant through the real provisioning action.
 *
 * New tenants require a subscription. Unless $subscribed is false, the tenant gets an
 * active annual subscription with plenty of seats (see subscribeTenant()), like a
 * company in normal operation.
 *
 * @param  array<string, string>  $attributes
 */
function provisionTenant(string $subdomain = 'acme', array $attributes = [], bool $subscribed = true): ProvisioningResult
{
    $result = app(ProvisionTenant::class)->create([
        'company_name' => Str::headline($subdomain).' Freight',
        'subdomain' => $subdomain,
        'admin_name' => 'Admin of '.$subdomain,
        'admin_email' => "admin@{$subdomain}.test",
        ...$attributes,
    ]);

    if ($subscribed) {
        subscribeTenant($result->tenant);
    }

    return $result;
}

/**
 * A test plan priced in USD: 1,000.00 base fee and 120.00 per seat per year.
 *
 * @param  array<string, mixed>  $attributes
 */
function testPlan(array $attributes = []): SubscriptionPlan
{
    return SubscriptionPlan::query()->create([
        'name' => 'Plan '.Str::random(6),
        'currency' => 'USD',
        'annual_base_fee' => 100000,
        'annual_seat_price' => 12000,
        'is_active' => true,
        ...$attributes,
    ]);
}

/**
 * Give a tenant an active term directly in the central database (no charge, no audit),
 * starting $daysAgo days ago. For tests of the billing workflows use the real actions.
 */
function subscribeTenant(Tenant $tenant, int $seats = 50, int $daysAgo = 10, ?SubscriptionPlan $plan = null): SubscriptionTerm
{
    $plan ??= testPlan();
    $startsOn = CarbonImmutable::today()->subDays($daysAgo);

    $subscription = TenantSubscription::query()->create([
        'tenant_id' => $tenant->getTenantKey(),
        'subscription_plan_id' => $plan->id,
        'currency' => $plan->currency,
        'activated_at' => now(),
    ]);

    return SubscriptionTerm::query()->create([
        'tenant_subscription_id' => $subscription->id,
        'tenant_id' => $tenant->getTenantKey(),
        'sequence' => 1,
        'kind' => SubscriptionTermKind::Initial,
        'subscription_plan_id' => $plan->id,
        'plan_name' => $plan->name,
        'currency' => $plan->currency,
        'annual_base_fee' => $plan->annual_base_fee,
        'annual_seat_price' => $plan->annual_seat_price,
        'initial_seats' => $seats,
        'seats' => $seats,
        'starts_on' => $startsOn,
        'ends_on' => Pricing::annualTermEnd($startsOn),
    ]);
}

/**
 * Register a tenant in the central database only (no tenant database is created),
 * for tests that need tenants in a given lifecycle state without provisioning them.
 *
 * @param  array<string, mixed>  $attributes
 */
function centralTenant(string $subdomain, TenantStatus $status = TenantStatus::Ready, array $attributes = []): Tenant
{
    $tenant = Tenant::create([
        'name' => Str::headline($subdomain).' Freight',
        'status' => $status,
        ...$attributes,
    ]);

    $tenant->domains()->create(['domain' => $subdomain]);

    return $tenant->load('domains');
}

/**
 * Provision a tenant and run the rest of the test inside its context: models use
 * its database and route() generates URLs on its subdomain.
 */
function actingInTenant(string $subdomain = 'acme', bool $subscribed = true): Tenant
{
    $tenant = provisionTenant($subdomain, subscribed: $subscribed)->tenant;

    tenancy()->initialize($tenant);
    URL::forceRootUrl(rtrim($tenant->url(), '/'));

    return $tenant;
}

/**
 * The provisioned Tenant Admin of the current tenant, past the forced password change.
 */
function tenantAdminUser(): User
{
    $admin = User::role(TenantRole::TenantAdmin->value)->firstOrFail();
    $admin->forceFill(['must_change_password' => false])->save();

    return $admin;
}

/**
 * Create a user in the current tenant holding exactly the given permissions (through a custom role).
 *
 * @param  list<TenantPermission>  $permissions
 * @param  array<string, mixed>  $attributes
 */
function userWithPermissions(array $permissions, array $attributes = []): User
{
    $role = Role::create(['name' => 'Custom '.Str::random(8), 'guard_name' => 'web']);
    $role->syncPermissions(array_map(fn (TenantPermission $permission): string => $permission->value, $permissions));

    return User::factory()->create($attributes)->assignRole($role);
}
