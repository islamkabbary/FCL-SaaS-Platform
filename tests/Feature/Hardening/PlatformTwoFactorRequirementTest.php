<?php

use App\Enums\ImpersonationEndReason;
use App\Enums\TenantRole;
use App\Models\PlatformAdmin;
use App\Models\User;

// Milestone 5.2: every Platform Admin needs confirmed two-factor authentication, so a
// stolen password alone can neither use the platform nor enroll its own authenticator
// to pass the support-access check.

beforeEach(function () {
    config(['platform.require_two_factor' => true]);
    $this->admin = PlatformAdmin::factory()->create(['email' => 'ops@platform.test']);
});

test('an admin without two-factor authentication is sent to enrollment from every platform page', function (string $route) {
    $this->actingAs($this->admin, 'platform')
        ->get(route($route))
        ->assertRedirect(route('platform.account.security'));
})->with(['platform.dashboard', 'platform.tenants.index', 'platform.plans.index', 'platform.support-sessions.index', 'platform.charges.index']);

test('a JSON request without two-factor authentication is refused', function () {
    $this->actingAs($this->admin, 'platform')
        ->getJson(route('platform.tenants.index'))
        ->assertForbidden();
});

test('the enrollment page, enrollment itself and logout stay available', function () {
    $this->actingAs($this->admin, 'platform');

    $this->get(route('platform.account.security'))->assertOk()->assertSee('required for Platform Admins');
    $this->post(route('platform.account.two-factor.enable'), ['password' => 'password'])->assertRedirect(route('platform.account.security'));
    expect($this->admin->refresh()->hasPendingTwoFactor())->toBeTrue();

    $this->post(route('platform.logout'))->assertRedirect(route('platform.login'));
});

test('an admin with confirmed two-factor authentication uses the platform normally', function () {
    enableTwoFactorFor($this->admin);

    $this->actingAs($this->admin, 'platform')->get(route('platform.dashboard'))->assertOk();
});

test('two-factor authentication cannot be disabled while it is required', function () {
    enableTwoFactorFor($this->admin);

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.account.security'))
        ->assertSee('cannot be disabled')
        ->assertDontSee('Disable two-factor authentication');

    $this->delete(route('platform.account.two-factor.disable'), ['password' => 'password', 'recovery_code' => 'recovery-code-one'])
        ->assertRedirect(route('platform.account.security'));

    expect($this->admin->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

test('without the requirement, the earlier behaviour is unchanged', function () {
    config(['platform.require_two_factor' => false]);

    $this->actingAs($this->admin, 'platform')->get(route('platform.dashboard'))->assertOk();
});

test('a running support session ends when its admin no longer has two-factor authentication', function () {
    enableSupportAccess(requireTwoFactor: true);
    enableTwoFactorFor($this->admin);
    $tenant = provisionTenant('acme')->tenant;

    [$session, $token] = startSupportSession($this->admin, $tenant);
    postHandoff($tenant, $token);
    $this->get($tenant->url('dashboard'))->assertOk();

    // For example reset from the command line after the session started.
    $this->admin->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

    $this->get($tenant->url('dashboard'))->assertRedirect($tenant->url('login'));
    expect($session->refresh()->end_reason)->toBe(ImpersonationEndReason::ActorUnauthorized)
        ->and(auth('web')->check())->toBeFalse();

    expect($tenant->run(fn () => User::role(TenantRole::TenantAdmin->value)->count()))->toBe(1);
});
