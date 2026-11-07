<?php

use App\Enums\TenantRole;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\MainAccountsSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config(['platform.seed_accounts_password' => 'seed-test-password']);
});

test('it creates the three Platform Admins and the demo company with an active subscription', function () {
    $this->seed(MainAccountsSeeder::class);

    expect(PlatformAdmin::query()->orderBy('id')->pluck('username')->all())->toBe(['islam', 'zyad', 'ibrahim']);

    $tenant = Tenant::query()->sole();

    expect($tenant->isReady())->toBeTrue()
        ->and($tenant->subscriptionStatus()->allowsAccess())->toBeTrue();

    $tenant->run(function (): void {
        expect(User::query()->orderBy('id')->pluck('username')->all())->toBe(['islam', 'zyad', 'ibrahim'])
            ->and(User::role(TenantRole::SuperAdmin->value)->count())->toBe(3)
            ->and(User::query()->where('must_change_password', true)->count())->toBe(0)
            ->and(Hash::check('seed-test-password', User::query()->where('username', 'zyad')->sole()->password))->toBeTrue();
    });
});

test('the accounts can sign in with their username', function () {
    $this->seed(MainAccountsSeeder::class);

    $this->post(route('platform.login.store'), ['login' => 'ibrahim', 'password' => 'seed-test-password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticated('platform');
    auth()->shouldUse('web');

    $this->post(Tenant::query()->sole()->url('login'), ['login' => 'zyad', 'password' => 'seed-test-password'])
        ->assertSessionHasNoErrors();
});

test('it can run again without creating duplicates', function () {
    $this->seed(MainAccountsSeeder::class);
    $this->seed(MainAccountsSeeder::class);

    expect(PlatformAdmin::query()->count())->toBe(3)
        ->and(Tenant::query()->count())->toBe(1)
        ->and(Tenant::query()->sole()->run(fn (): int => User::query()->count()))->toBe(3);
});

test('an existing admin with the same username is reused and keeps their email', function () {
    $existing = PlatformAdmin::factory()->create(['name' => 'Zyad', 'email' => 'zyad@company.test']);

    expect($existing->username)->toBe('zyad');

    $this->seed(MainAccountsSeeder::class);

    expect(PlatformAdmin::query()->count())->toBe(3)
        ->and($existing->refresh()->email)->toBe('zyad@company.test')
        ->and(Hash::check('seed-test-password', $existing->password))->toBeTrue();
});

test('it refuses to run in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => app(MainAccountsSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'must not run in production');

    expect(PlatformAdmin::query()->count())->toBe(0);
});

test('it refuses to run without a password', function () {
    config(['platform.seed_accounts_password' => null]);

    expect(fn () => $this->seed(MainAccountsSeeder::class))
        ->toThrow(RuntimeException::class, 'SEED_ACCOUNTS_PASSWORD');

    expect(PlatformAdmin::query()->count())->toBe(0);
});
