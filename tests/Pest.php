<?php

use App\Actions\Fcl\CreateParty;
use App\Actions\Impersonation\StartSupportSession;
use App\Actions\Tenancy\ProvisioningResult;
use App\Actions\Tenancy\ProvisionTenant;
use App\Auth\PlatformTwoFactor;
use App\Billing\Pricing;
use App\Enums\SubscriptionTermKind;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Branch;
use App\Models\Fcl\Party;
use App\Models\PlatformAdmin;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTerm;
use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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

/**
 * Enable platform support access for the test (it is disabled by default). Two-factor
 * authentication is not required unless a test asks for it.
 */
function enableSupportAccess(bool $requireTwoFactor = false): void
{
    config([
        'impersonation.support.enabled' => true,
        'impersonation.support.require_two_factor' => $requireTwoFactor,
    ]);
}

/**
 * Start a support session through the real action (no HTTP), as the given Platform Admin.
 *
 * @return array{0: SupportSession, 1: string} The session and the plaintext handoff credential.
 */
function startSupportSession(PlatformAdmin $admin, Tenant $tenant, ?int $targetUserId = null, string $reason = 'Ticket 4711: dashboard does not load'): array
{
    $targetUserId ??= $tenant->run(fn (): int => User::role(TenantRole::TenantAdmin->value)->firstOrFail()->id);

    return app(StartSupportSession::class)->handle($admin, $tenant, $targetUserId, $reason);
}

/**
 * Post a handoff credential to the tenant subdomain, as the Platform Admin's browser does.
 */
function postHandoff(Tenant $tenant, string $token): TestResponse
{
    auth()->shouldUse('web');

    return test()->post($tenant->url('support/handoff'), ['token' => $token]);
}

/**
 * Send a real Livewire update request for a component rendered at $path (the page
 * component unless $component names another), as the browser would after loading that
 * page in the current session.
 *
 * @param  list<array{method: string, params: list<mixed>}>  $calls
 * @param  array<string, mixed>  $updates
 */
function livewireRequest(Tenant $tenant, string $path, array $calls = [], array $updates = [], ?string $component = null): TestResponse
{
    $html = test()->get($tenant->url($path))->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', (string) $html, $matches);

    // The page component by default, or the (nested) component with the given name.
    $snapshot = collect($matches[1])
        ->map(fn (string $encoded): string => html_entity_decode($encoded, ENT_QUOTES))
        ->first(fn (string $snapshot): bool => $component === null || json_decode($snapshot, true)['memo']['name'] === $component);

    $response = test()->withHeaders(['X-Livewire' => '1'])->postJson(
        $tenant->url(route('livewire.update', absolute: false)),
        ['components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => array_map(fn (array $call): array => [...$call, 'metadata' => []], $calls)]]],
    );

    test()->flushHeaders();

    return $response;
}

/**
 * Give a Platform Admin confirmed two-factor authentication with two known recovery codes.
 * Returns the TOTP secret.
 */
function enableTwoFactorFor(PlatformAdmin $admin): string
{
    $secret = app(PlatformTwoFactor::class)->generateSecret();

    $admin->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_recovery_codes' => ['recovery-code-one', 'recovery-code-two'],
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $secret;
}

/**
 * Run the callback and return the first query of every transaction it opens on the given
 * connection. Under REPEATABLE READ the first plain read fixes a transaction's snapshot,
 * so a transaction that must see other transactions' commits has to lock first.
 *
 * @return list<string>
 */
function firstQueriesOfTransactions(Closure $callback, string $connection = 'tenant'): array
{
    $first = [];
    $opened = false;

    Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$opened, $connection): void {
        $opened = $opened || $event->connectionName === $connection;
    });
    DB::listen(function (QueryExecuted $query) use (&$first, &$opened, $connection): void {
        if ($opened && $query->connectionName === $connection) {
            $first[] = $query->sql;
            $opened = false;
        }
    });

    $callback();

    return $first;
}

/**
 * Log a tenant user in over HTTP on the tenant's subdomain, with the password "password".
 */
function loginToTenant(Tenant $tenant, User $user): void
{
    auth()->shouldUse('web');
    $tenant->run(fn () => $user->forceFill(['password' => 'password', 'must_change_password' => false])->save());

    test()->post($tenant->url('login'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();
}

/**
 * Start an employee view from the Employees page, as the browser does (Livewire request).
 */
function startEmployeeView(Tenant $tenant, int $employeeId, string $password = 'password', string $reason = 'Reproduce ticket 88 for this employee'): TestResponse
{
    return livewireRequest($tenant, 'employees', [['method' => 'startImpersonation', 'params' => []]], [
        'impersonationTargetId' => $employeeId,
        'impersonationReason' => $reason,
        'impersonationPassword' => $password,
    ]);
}

/**
 * Store a session row for a user in the current database (tenant or central), as the
 * database session driver would. Used to prove which sessions an action signs out.
 */
function storeSessionRow(string $id, ?int $userId): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => now()->timestamp]);
}

/**
 * Store sessions in the database for this test, as in production (phpunit.xml uses the
 * array driver, which hides which session rows an action deletes). Call it before the
 * test's first request.
 */
function useDatabaseSessions(): void
{
    config(['session.driver' => 'database']);
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

/*
|--------------------------------------------------------------------------
| FCL Operations
|--------------------------------------------------------------------------
*/

/**
 * The default branch of the current tenant, created by provisioning.
 */
function defaultBranch(): Branch
{
    return Branch::query()->where('is_default', true)->sole();
}

/**
 * A branch besides the default one in the current tenant. Companies only have their default
 * branch so far (Q-BR02), so tests of branch behaviour seed the other branches they need.
 */
function extraBranch(string $code = 'ALX', string $name = 'Alexandria Branch'): Branch
{
    return Branch::factory()->create(['code' => $code, 'name' => $name]);
}

/**
 * A user of the current tenant working in the given branch, holding exactly the given permissions.
 *
 * @param  list<TenantPermission>  $permissions
 * @param  array<string, mixed>  $attributes
 */
function userInBranch(Branch $branch, array $permissions, array $attributes = []): User
{
    return userWithPermissions($permissions, [...$attributes, 'primary_branch_id' => $branch->id]);
}

/**
 * A party of the current tenant, created through the audited action by $actor (the Tenant
 * Admin by default), as the application creates them.
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<int>  $confirmedSimilarIds  The similar parties a duplicate warning would list.
 */
function createParty(array $attributes = [], ?User $actor = null, array $confirmedSimilarIds = []): Party
{
    return app(CreateParty::class)->handle(
        $actor ?? tenantAdminUser(),
        [...Arr::only(Party::factory()->raw(), Party::EditableAttributes), ...$attributes],
        $confirmedSimilarIds,
    );
}
