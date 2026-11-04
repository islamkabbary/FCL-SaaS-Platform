<?php

use App\Actions\Users\GenerateUsername;
use App\Models\PlatformAdmin;
use App\Models\User;

describe('tenant login', function () {
    beforeEach(function () {
        $this->tenant = actingInTenant();
        $this->user = User::factory()->create(['name' => 'Sara Ali', 'email' => 'sara@acme.test']);
    });

    test('a user signs in with their username or their email', function (string $login) {
        $this->post(route('login.store'), ['login' => $login, 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($this->user, 'web');
    })->with(['username' => 'sara.ali', 'email' => 'sara@acme.test', 'capitals' => 'SARA.Ali']);

    test('a wrong password is refused for a username', function () {
        $this->post(route('login.store'), ['login' => 'sara.ali', 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['login' => __('auth.failed')]);

        $this->assertGuest('web');
    });

    test('a deactivated user cannot sign in with their username', function () {
        $this->user->forceFill(['is_active' => false])->save();

        $this->post(route('login.store'), ['login' => 'sara.ali', 'password' => 'password'])
            ->assertSessionHasErrors(['login' => 'This account has been deactivated.']);
    });

    test('a non-string login is a validation error', function () {
        $this->post(route('login.store'), ['login' => ['sara.ali'], 'password' => 'password'])
            ->assertSessionHasErrors('login');
    });

    test('repeated failures with a username are throttled', function () {
        foreach (range(1, 5) as $attempt) {
            $this->post(route('login.store'), ['login' => 'sara.ali', 'password' => 'wrong-password']);
        }

        $this->post(route('login.store'), ['login' => 'sara.ali', 'password' => 'password'])
            ->assertTooManyRequests();
    });
});

test('the same username in another company does not sign in there', function () {
    $acme = provisionTenant('acme')->tenant;
    $globex = provisionTenant('globex')->tenant;

    $acme->run(fn () => User::factory()->create(['name' => 'Sara Ali', 'password' => 'acme-password']));
    $globex->run(fn () => User::factory()->create(['name' => 'Sara Ali', 'password' => 'globex-password']));

    $this->post($globex->url('login'), ['login' => 'sara.ali', 'password' => 'acme-password'])
        ->assertSessionHasErrors('login');

    $this->post($globex->url('login'), ['login' => 'sara.ali', 'password' => 'globex-password'])
        ->assertSessionHasNoErrors();
});

test('a Platform Admin signs in with a username or an email', function (string $login) {
    PlatformAdmin::factory()->create(['name' => 'Ops Lead', 'email' => 'ops@platform.test']);

    $this->post(route('platform.login.store'), ['login' => $login, 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticated('platform');
})->with(['username' => 'ops.lead', 'email' => 'ops@platform.test']);

test('a wrong Platform Admin password is refused for a username', function () {
    PlatformAdmin::factory()->create(['name' => 'Ops Lead']);

    $this->post(route('platform.login.store'), ['login' => 'ops.lead', 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['login' => __('auth.failed')]);

    $this->assertGuest('platform');
});

describe('generated usernames', function () {
    beforeEach(function () {
        actingInTenant();
    });

    test('a name becomes a username, and repeated names get a number', function () {
        $names = collect(['Islam Kabbary', 'Islam Kabbary', 'Islam Kabbary'])
            ->map(fn (string $name): string => User::factory()->create(['name' => $name])->username);

        expect($names->all())->toBe(['islam.kabbary', 'islam.kabbary2', 'islam.kabbary3']);
    });

    test('names without usable letters fall back to a valid username', function (string $name, string $expected) {
        expect(app(GenerateUsername::class)->handle(User::class, $name))->toBe($expected);
    })->with([
        'symbols' => ['***', 'user'],
        'digits' => ['2024', 'user'],
        'too short' => ['Al', 'user'],
    ]);

    test('an Arabic name gets a valid username', function () {
        expect(User::factory()->create(['name' => 'زياد أشرف'])->username)->toMatch('/^[a-z][a-z0-9._-]{2,29}$/');
    });

    test('a very long name stays within the limit, also with a number', function () {
        $name = str_repeat('abcdefghij', 5);

        $first = User::factory()->create(['name' => $name])->username;
        $second = User::factory()->create(['name' => $name])->username;

        expect(strlen($first))->toBe(30)
            ->and(strlen($second))->toBe(30)
            ->and($second)->not->toBe($first);
    });
});
