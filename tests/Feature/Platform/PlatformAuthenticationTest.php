<?php

use App\Models\PlatformAdmin;

test('platform login screen can be rendered on the central domain', function () {
    $this->get(route('platform.login'))->assertOk();
});

test('platform admins can authenticate on the central domain', function () {
    $admin = PlatformAdmin::factory()->create();

    $response = $this->post(route('platform.login.store'), [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('platform.dashboard', absolute: false));

    $this->assertAuthenticatedAs($admin, 'platform');
    $this->assertGuest('web');
});

test('platform admins can not authenticate with an invalid password', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->post(route('platform.login.store'), [
        'email' => $admin->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest('platform');
});

test('platform login is locked after too many failed attempts', function () {
    $admin = PlatformAdmin::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('platform.login.store'), ['email' => $admin->email, 'password' => 'wrong-password']);
    }

    $this->post(route('platform.login.store'), [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest('platform');
});

test('guests are redirected from the platform area to the platform login', function () {
    $this->get(route('platform.tenants.index'))->assertRedirect(route('platform.login'));
});

test('platform admins can log out', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')
        ->post(route('platform.logout'))
        ->assertRedirect(route('platform.login'));

    $this->assertGuest('platform');
});

test('the platform area is not reachable from a tenant subdomain', function () {
    $tenant = provisionTenant('acme')->tenant;
    $admin = PlatformAdmin::factory()->create();

    $this->get($tenant->url('admin/login'))->assertNotFound();
    $this->actingAs($admin, 'platform')->get($tenant->url('admin/tenants'))->assertNotFound();
});
