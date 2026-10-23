<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Auth\DummyPasswordCheck;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// Milestone 5.2: login hardening for tenant users and Platform Admins.

test('a tenant login with an array email is a validation error, not a server error', function () {
    $tenant = actingInTenant();
    auth()->shouldUse('web');

    $this->post($tenant->url('login'), ['email' => ['x@acme.test'], 'password' => 'secret'])
        ->assertStatus(302)
        ->assertSessionHasErrors('email');
});

test('a tenant login for an unknown email still checks a password hash', function () {
    $tenant = actingInTenant();
    auth()->shouldUse('web');
    Hash::spy();

    $this->post($tenant->url('login'), ['email' => 'nobody@acme.test', 'password' => 'secret'])->assertSessionHasErrors('email');

    Hash::shouldHaveReceived('check')->once()->withArgs(fn (string $password, string $hash) => $hash === DummyPasswordCheck::Hash);
});

test('a platform login for an unknown email still checks a password hash', function () {
    Hash::spy();

    $this->post(route('platform.login.store'), ['email' => 'nobody@platform.test', 'password' => 'secret'])->assertSessionHasErrors('email');

    Hash::shouldHaveReceived('check')->once()->withArgs(fn (string $password, string $hash) => $hash === DummyPasswordCheck::Hash);
});

test('the dummy hash uses the production bcrypt cost', function () {
    expect(password_get_info(DummyPasswordCheck::Hash))->toMatchArray(['algoName' => 'bcrypt', 'options' => ['cost' => 12]]);
});

test('a platform admin account is locked after twenty failures spread over many IP addresses', function () {
    $admin = PlatformAdmin::factory()->create(['email' => 'ops@platform.test', 'password' => 'correct-password']);

    foreach (range(1, 20) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$attempt}"])
            ->post(route('platform.login.store'), ['email' => $admin->email, 'password' => 'wrong-password']);
    }

    // A new address with the right password is refused while the account is locked.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
        ->post(route('platform.login.store'), ['email' => $admin->email, 'password' => 'correct-password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest('platform');
});

test('resetting a forgotten password clears the temporary-password flag', function () {
    actingInTenant();
    $user = User::factory()->create(['must_change_password' => true]);

    app(ResetUserPassword::class)->reset($user, ['password' => 'A-new-Strong-pass-2026!', 'password_confirmation' => 'A-new-Strong-pass-2026!']);

    expect($user->fresh()->must_change_password)->toBeFalse();
});
