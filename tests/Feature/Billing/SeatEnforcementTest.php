<?php

use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\SetEmployeeActivation;
use App\Billing\SeatGuard;
use App\Enums\TenantRole;
use App\Exceptions\NoSeatAvailable;
use App\Models\BillingCharge;
use App\Models\SubscriptionTerm;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * A tenant whose subscription has exactly $seats purchased seats; the Tenant Admin uses one.
 */
function tenantWithSeats(int $seats, string $subdomain = 'acme'): array
{
    $tenant = actingInTenant($subdomain, subscribed: false);
    subscribeTenant($tenant, seats: $seats);

    return [$tenant, tenantAdminUser()];
}

function newEmployee(User $admin, string $email): User
{
    return app(CreateEmployee::class)->handle($admin, ['name' => 'Employee', 'email' => $email])->employee;
}

test('the active tenant admin consumes one seat', function () {
    [, $admin] = tenantWithSeats(3);

    $usage = app(SeatGuard::class)->usage();

    expect($usage->limit)->toBe(3)
        ->and($usage->used)->toBe(1)
        ->and($usage->available())->toBe(2);
});

test('active employees consume seats and inactive employees do not', function () {
    [, $admin] = tenantWithSeats(5);
    User::factory()->count(2)->create();
    User::factory()->inactive()->count(3)->create();

    expect(app(SeatGuard::class)->usage()->used)->toBe(3); // admin + 2 active
});

test('employee creation is blocked when every purchased seat is used', function () {
    [, $admin] = tenantWithSeats(2);
    newEmployee($admin, 'one@acme.test');

    expect(fn () => newEmployee($admin, 'two@acme.test'))
        ->toThrow(NoSeatAvailable::class, 'All 2 purchased seats are in use (2 active users).');

    expect(User::query()->where('email', 'two@acme.test')->exists())->toBeFalse();
});

test('the employees page explains that no seats are available', function () {
    [, $admin] = tenantWithSeats(1);

    Livewire::actingAs($admin)
        ->test('pages::employees.index')
        ->assertSee('1 of 1 purchased seats in use')
        ->assertSee('No purchased seats are available')
        ->call('create')
        ->set('name', 'Bob')
        ->set('email', 'bob@acme.test')
        ->call('save')
        ->assertDispatched('toast-show');

    expect(User::query()->where('email', 'bob@acme.test')->exists())->toBeFalse();
});

test('reactivating an employee is blocked when every purchased seat is used', function () {
    [, $admin] = tenantWithSeats(2);
    $former = User::factory()->inactive()->create(['email' => 'former@acme.test'])->assignRole(TenantRole::Employee->value);
    newEmployee($admin, 'current@acme.test');

    expect(fn () => app(SetEmployeeActivation::class)->handle($admin, $former, true))
        ->toThrow(NoSeatAvailable::class);

    expect($former->refresh()->is_active)->toBeFalse();
});

test('deactivation frees a seat for a replacement without changing the entitlement or billing', function () {
    [$tenant, $admin] = tenantWithSeats(2);
    $leaving = newEmployee($admin, 'leaving@acme.test');
    $term = SubscriptionTerm::query()->where('tenant_id', $tenant->id)->sole();

    app(SetEmployeeActivation::class)->handle($admin, $leaving, false);

    $replacement = newEmployee($admin, 'replacement@acme.test');

    expect($replacement->is_active)->toBeTrue()
        ->and($term->refresh()->seats)->toBe(2)
        ->and(BillingCharge::query()->count())->toBe(0)
        ->and(app(SeatGuard::class)->usage()->available())->toBe(0);
});

test('a reactivated employee can take a seat freed by a deactivation', function () {
    [, $admin] = tenantWithSeats(2);
    $first = newEmployee($admin, 'first@acme.test');
    app(SetEmployeeActivation::class)->handle($admin, $first, false);
    $second = newEmployee($admin, 'second@acme.test');
    app(SetEmployeeActivation::class)->handle($admin, $second, false);

    app(SetEmployeeActivation::class)->handle($admin, $first, true);

    expect($first->refresh()->is_active)->toBeTrue()
        ->and(fn () => app(SetEmployeeActivation::class)->handle($admin, $second, true))->toThrow(NoSeatAvailable::class);
});

test('a tenant without a subscription cannot add users', function () {
    actingInTenant(subscribed: false);
    $admin = tenantAdminUser();

    expect(fn () => newEmployee($admin, 'bob@acme.test'))
        ->toThrow(NoSeatAvailable::class, 'Your company has no active subscription');
});

test('an expired subscription cannot add users', function () {
    [, $admin] = tenantWithSeats(10);

    $this->travel(400)->days();

    expect(fn () => newEmployee($admin, 'bob@acme.test'))->toThrow(NoSeatAvailable::class);
});

test('legacy tenants keep unlimited seats until a subscription is activated', function () {
    $tenant = actingInTenant(subscribed: false);
    $tenant->forceFill(['subscription_required' => false])->save();
    $admin = tenantAdminUser();

    foreach (range(1, 3) as $number) {
        newEmployee($admin, "user{$number}@acme.test");
    }

    expect(app(SeatGuard::class)->usage()->isUnlimited())->toBeTrue()
        ->and(User::query()->active()->count())->toBe(4)
        ->and(TenantSubscription::query()->count())->toBe(0);
});

test('seat-consuming operations of a tenant are serialized by a per-tenant lock', function () {
    [$tenant, $admin] = tenantWithSeats(5);
    config(['billing.seat_lock_timeout' => 1]);

    // A second database session plays a concurrent request holding the tenant's seat lock.
    config(['database.connections.concurrent' => config('database.connections.mysql')]);
    $concurrent = DB::connection('concurrent');
    $lock = 'fcl_seats_'.$tenant->getTenantKey();
    expect((int) $concurrent->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock])->acquired)->toBe(1);

    try {
        expect(fn () => newEmployee($admin, 'blocked@acme.test'))->toThrow(NoSeatAvailable::class, 'Another seat change is in progress');
        expect(User::query()->where('email', 'blocked@acme.test')->exists())->toBeFalse();
    } finally {
        $concurrent->select('SELECT RELEASE_LOCK(?)', [$lock]);
        DB::purge('concurrent');
    }

    // Once the other operation finished, the request goes through and releases the lock.
    expect(newEmployee($admin, 'after@acme.test')->is_active)->toBeTrue()
        ->and((int) DB::connection()->selectOne('SELECT IS_FREE_LOCK(?) AS free', [$lock])->free)->toBe(1);
});

test('the seat check re-reads usage under the lock', function () {
    [$tenant, $admin] = tenantWithSeats(2);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    newEmployee($admin, 'bob@acme.test');

    $lockAt = collect($queries)->search(fn (string $sql) => str_contains($sql, 'GET_LOCK'));
    $countAt = collect($queries)->search(fn (string $sql, int $index) => $index > $lockAt && str_contains($sql, 'count(*)') && str_contains($sql, '`users`'));
    $insertAt = collect($queries)->search(fn (string $sql) => str_starts_with($sql, 'insert into `users`'));
    $releaseAt = collect($queries)->search(fn (string $sql) => str_contains($sql, 'RELEASE_LOCK'));

    expect($lockAt)->toBeInt()
        ->and($countAt)->toBeGreaterThan($lockAt)
        ->and($insertAt)->toBeGreaterThan($countAt)
        ->and($releaseAt)->toBeGreaterThan($insertAt);
});

test('seat limits of one tenant do not affect another', function () {
    // Provisioning ends any tenant context, so the other tenant is provisioned first.
    $globex = provisionTenant('globex', subscribed: false)->tenant;
    subscribeTenant($globex, seats: 3);

    [$acme, $admin] = tenantWithSeats(1, 'acme');

    $globex->run(function () {
        $globexAdmin = tenantAdminUser();
        expect(newEmployee($globexAdmin, 'g1@globex.test')->is_active)->toBeTrue()
            ->and(app(SeatGuard::class)->usage()->limit)->toBe(3);
    });

    expect(fn () => newEmployee($admin, 'a1@acme.test'))->toThrow(NoSeatAvailable::class);
});

test('reactivating an employee who is already active needs no free seat', function () {
    [, $admin] = tenantWithSeats(2);
    $employee = newEmployee($admin, 'active@acme.test');

    app(SetEmployeeActivation::class)->handle($admin, $employee, true);

    expect($employee->refresh()->is_active)->toBeTrue();
});
