<?php

use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\Hash;

test('a platform admin can be created from the console', function () {
    $this->artisan('platform:create-admin', ['--name' => 'Ops', '--email' => 'Ops@Example.com'])
        ->expectsQuestion('Password', 'a-long-secure-password')
        ->expectsQuestion('Confirm password', 'a-long-secure-password')
        ->assertSuccessful();

    $admin = PlatformAdmin::query()->sole();

    expect($admin->email)->toBe('ops@example.com')
        ->and(Hash::check('a-long-secure-password', $admin->password))->toBeTrue();
});

test('a platform admin is not created with a short password', function () {
    $this->artisan('platform:create-admin', ['--name' => 'Ops', '--email' => 'ops@example.com'])
        ->expectsQuestion('Password', 'short')
        ->expectsQuestion('Confirm password', 'short')
        ->assertFailed();

    expect(PlatformAdmin::query()->count())->toBe(0);
});
