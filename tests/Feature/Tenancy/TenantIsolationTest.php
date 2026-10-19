<?php

use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

test('each tenant has its own database containing only its own users', function () {
    $acme = provisionTenant('acme')->tenant;
    $globex = provisionTenant('globex')->tenant;

    expect($acme->database()->getName())->not->toBe($globex->database()->getName());

    $acme->run(fn () => User::factory()->create(['email' => 'only-in-acme@example.com']));

    $acmeEmails = $acme->run(fn () => User::query()->pluck('email')->sort()->values()->all());
    $globexEmails = $globex->run(fn () => User::query()->pluck('email')->all());

    expect($acmeEmails)->toBe(['admin@acme.test', 'only-in-acme@example.com'])
        ->and($globexEmails)->toBe(['admin@globex.test']);
});

test('tenant context switches the default connection to the tenant database', function () {
    $acme = provisionTenant('acme')->tenant;

    $databaseInTenantContext = $acme->run(fn () => DB::connection()->getDatabaseName());

    expect($databaseInTenantContext)->toBe($acme->database()->getName())
        ->and(DB::connection()->getDatabaseName())->toBe('fcl_central_testing');
});

test('credentials of one tenant are rejected by another tenant', function () {
    $acme = provisionTenant('acme');
    $globex = provisionTenant('globex')->tenant;

    $this->post($globex->url('login'), [
        'email' => $acme->adminEmail,
        'password' => $acme->temporaryPassword,
    ])->assertSessionHasErrors('email');

    $this->assertGuest('web');
});

test('the same email on two tenants authenticates only with that tenant\'s password', function () {
    $acme = provisionTenant('acme')->tenant;
    $globex = provisionTenant('globex')->tenant;

    $acme->run(fn () => User::factory()->create(['email' => 'shared@example.com', 'password' => 'acme-password']));
    $globex->run(fn () => User::factory()->create(['email' => 'shared@example.com', 'password' => 'globex-password']));

    $this->post($globex->url('login'), ['email' => 'shared@example.com', 'password' => 'acme-password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest('web');

    $this->post($globex->url('login'), ['email' => 'shared@example.com', 'password' => 'globex-password'])
        ->assertSessionHasNoErrors();
    expect(auth('web')->user()->email)->toBe('shared@example.com')
        ->and(tenant('id'))->toBe($globex->id);
});

test('a session established on one tenant is rejected by another tenant', function () {
    $acme = provisionTenant('acme')->tenant;
    $globex = provisionTenant('globex')->tenant;
    $acmeUser = $acme->run(fn () => User::factory()->create(['email' => 'user@acme.test']));
    $globexUser = $globex->run(fn () => User::factory()->create(['email' => 'user@globex.test']));
    expect($globexUser->id)->toBe($acmeUser->id);

    $this->post($acme->url('login'), ['email' => 'user@acme.test', 'password' => 'password'])
        ->assertSessionHasNoErrors();

    // Replay the same session (it holds a user id that also exists in globex) against globex.
    auth()->forgetGuards();
    $authenticatedUsers = [];
    Event::listen(Authenticated::class, function (Authenticated $event) use (&$authenticatedUsers) {
        $authenticatedUsers[] = $event->user->email;
    });

    $this->get($globex->url('dashboard'))->assertForbidden();

    // The session scope is rejected before the guard ever resolves a user from it.
    expect($authenticatedUsers)->toBe([]);
});

test('tenant sessions are stored in the tenant database, not the central one', function () {
    config(['session.driver' => 'database']);
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    auth()->forgetGuards();

    $tenant = provisionTenant('acme')->tenant;

    // The Fortify login controller resolves the session guard before tenancy is initialized.
    $this->get($tenant->url('login'))->assertOk();

    expect(DB::connection('mysql')->table('sessions')->count())->toBe(0)
        ->and($tenant->run(fn () => DB::table('sessions')->count()))->toBe(1);
});

test('failed tenant logins are throttled per tenant', function () {
    $acme = provisionTenant('acme')->tenant;
    $globex = provisionTenant('globex')->tenant;
    $acme->run(fn () => User::factory()->create(['email' => 'shared@example.com']));
    $globex->run(fn () => User::factory()->create(['email' => 'shared@example.com']));

    foreach (range(1, 5) as $attempt) {
        $this->post($acme->url('login'), ['email' => 'shared@example.com', 'password' => 'wrong-password']);
    }

    $this->post($acme->url('login'), ['email' => 'shared@example.com', 'password' => 'password'])
        ->assertTooManyRequests();

    $this->post($globex->url('login'), ['email' => 'shared@example.com', 'password' => 'password'])
        ->assertSessionHasNoErrors();
});

test('platform admin credentials are rejected by tenant login', function () {
    $tenant = provisionTenant('acme')->tenant;
    $admin = PlatformAdmin::factory()->create();

    $this->post($tenant->url('login'), ['email' => $admin->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest('web');
});

test('tenant admin credentials are rejected by platform login', function () {
    $result = provisionTenant('acme');

    $this->post(route('platform.login.store'), [
        'email' => $result->adminEmail,
        'password' => $result->temporaryPassword,
    ])->assertSessionHasErrors('email');

    $this->assertGuest('platform');
});

test('a platform admin session does not grant access to a tenant', function () {
    $tenant = provisionTenant('acme')->tenant;
    $admin = PlatformAdmin::factory()->create();

    $this->post(route('platform.login.store'), ['email' => $admin->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($admin, 'platform');

    // Replay the platform session against the tenant.
    auth()->forgetGuards();

    $this->get($tenant->url('dashboard'))->assertRedirect($tenant->url('login'));
});

test('a tenant user session does not grant access to the platform area', function () {
    $tenant = provisionTenant('acme')->tenant;
    $user = $tenant->run(fn () => User::query()->first());

    $this->actingAs($user, 'web')
        ->get(route('platform.tenants.index'))
        ->assertRedirect(route('platform.login'));
});
