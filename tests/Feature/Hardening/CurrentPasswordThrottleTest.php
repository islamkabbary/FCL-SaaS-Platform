<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

// Milestone 5.2: a hijacked session must not be able to guess the current password.

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->user = User::factory()->create(['email' => 'me@acme.test']);
});

test('the security page locks the current-password check after five wrong guesses', function () {
    $component = Livewire::actingAs($this->user)->test('pages::settings.security');

    foreach (range(1, 5) as $attempt) {
        $component->set('current_password', "guess-{$attempt}")
            ->set('password', 'A-new-Strong-pass-2026!')
            ->set('password_confirmation', 'A-new-Strong-pass-2026!')
            ->call('updatePassword')
            ->assertHasErrors(['current_password']);
    }

    // The right password is refused too while locked, so a dictionary cannot finish.
    $component->set('current_password', 'password')
        ->set('password', 'A-new-Strong-pass-2026!')
        ->set('password_confirmation', 'A-new-Strong-pass-2026!')
        ->call('updatePassword')
        ->assertHasErrors(['current_password']);

    expect($component->errors()->first('current_password'))->toContain('Too many attempts')
        ->and(Hash::check('password', $this->user->refresh()->password))->toBeTrue();
});

test('the delete-account modal locks the password check after five wrong guesses', function () {
    $component = Livewire::actingAs($this->user)->test('pages::settings.delete-user-modal');

    foreach (range(1, 5) as $attempt) {
        $component->set('password', "guess-{$attempt}")->call('deleteUser')->assertHasErrors(['password']);
    }

    $component->set('password', 'password')->call('deleteUser')->assertHasErrors(['password']);

    expect($this->user->fresh())->not->toBeNull();
});

test('the profile email change shares the same lockout', function () {
    $component = Livewire::actingAs($this->user)->test('pages::settings.profile');

    foreach (range(1, 5) as $attempt) {
        $component->set('email', 'thief@evil.test')->set('current_password', "guess-{$attempt}")
            ->call('updateProfileInformation')->assertHasErrors(['current_password']);
    }

    $component->set('email', 'thief@evil.test')->set('current_password', 'password')
        ->call('updateProfileInformation')->assertHasErrors(['current_password']);

    expect($this->user->refresh()->email)->toBe('me@acme.test');
});

test('a correct password clears earlier failures, and the lockout is per user', function () {
    $component = Livewire::actingAs($this->user)->test('pages::settings.security');

    foreach (range(1, 4) as $attempt) {
        $component->set('current_password', "guess-{$attempt}")
            ->set('password', 'A-new-Strong-pass-2026!')
            ->set('password_confirmation', 'A-new-Strong-pass-2026!')
            ->call('updatePassword');
    }

    $component->set('current_password', 'password')
        ->set('password', 'A-new-Strong-pass-2026!')
        ->set('password_confirmation', 'A-new-Strong-pass-2026!')
        ->call('updatePassword')
        ->assertHasNoErrors();

    $other = User::factory()->create();
    Livewire::actingAs($other)->test('pages::settings.delete-user-modal')
        ->set('password', 'password')->call('deleteUser')->assertHasNoErrors();
});
