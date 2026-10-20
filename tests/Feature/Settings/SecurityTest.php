<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    actingInTenant();
});

test('security settings page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('security.edit'));

    $response->assertOk();
});

test('security settings page renders without two factor when feature is disabled', function () {
    config(['fortify.features' => []]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Update password')
        ->assertDontSee('Manage your passkeys for passwordless sign-in')
        ->assertDontSee('Add a passkey to sign in without a password')
        ->assertDontSee('Two-factor authentication');
});

test('password can be updated', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.security')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasNoErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.security')
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasErrors(['current_password']);
});

test('changing the password ends the user\'s other sessions and rotates the remember token', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create(['password' => Hash::make('password'), 'remember_token' => 'old-token']);
    $other = User::factory()->create();

    $this->actingAs($user);
    $current = session()->getId();

    DB::table('sessions')->insert([
        ['id' => $current, 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp],
        ['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp],
        ['id' => 'someone-else', 'user_id' => $other->id, 'payload' => '', 'last_activity' => now()->timestamp],
    ]);

    Livewire::test('pages::settings.security')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(DB::table('sessions')->pluck('id')->all())->toEqualCanonicalizing([$current, 'someone-else'])
        ->and($user->refresh()->remember_token)->not->toBe('old-token');
});
