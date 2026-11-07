<?php

use App\Models\PlatformAdmin;

beforeEach(function () {
    config(['platform.two_factor_enabled' => false, 'platform.require_two_factor' => true]);
});

test('an admin with confirmed two-factor signs in without a code', function () {
    $admin = PlatformAdmin::factory()->create(['email' => 'ops@platform.test']);
    enableTwoFactorFor($admin);

    $this->post(route('platform.login.store'), ['login' => 'ops@platform.test', 'password' => 'password'])
        ->assertRedirect(route('platform.dashboard', absolute: false));

    $this->assertAuthenticatedAs($admin, 'platform');
});

test('an admin without two-factor is not sent to set it up', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')->get(route('platform.dashboard'))
        ->assertOk()
        ->assertDontSee('account-security-link');
});

test('the two-factor pages do not exist', function (string $method, string $route) {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')->{$method}(route($route))->assertNotFound();
})->with([
    ['get', 'platform.account.security'],
    ['post', 'platform.account.two-factor.enable'],
    ['post', 'platform.account.two-factor.confirm'],
    ['post', 'platform.account.two-factor.recovery-codes'],
    ['delete', 'platform.account.two-factor.disable'],
]);

test('the code step does not exist for a guest', function () {
    $this->get(route('platform.two-factor.challenge'))->assertNotFound();
});

test('a support session needs only the password, without a code field', function () {
    config(['impersonation.support.enabled' => true, 'impersonation.support.require_two_factor' => true]);

    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenant('acme')->tenant;
    $targetId = $tenant->run(fn (): int => superAdminUser()->id);

    $this->actingAs($admin, 'platform')
        ->get(route('platform.support-sessions.create', $tenant))
        ->assertOk()
        ->assertDontSee('name="code"', false);

    $this->actingAs($admin, 'platform')
        ->post(route('platform.support-sessions.store', $tenant), [
            'target_user_id' => $targetId,
            'reason' => 'Ticket 4711: dashboard does not load',
            'password' => 'password',
        ])
        ->assertOk()
        ->assertSessionHasNoErrors();
});

test('switching it on brings the saved two-factor back', function () {
    $admin = PlatformAdmin::factory()->create(['email' => 'ops@platform.test']);
    enableTwoFactorFor($admin);
    config(['platform.two_factor_enabled' => true]);

    $this->post(route('platform.login.store'), ['login' => 'ops@platform.test', 'password' => 'password'])
        ->assertRedirect(route('platform.two-factor.challenge'));

    $this->assertGuest('platform');
});
