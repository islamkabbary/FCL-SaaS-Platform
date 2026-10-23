<?php

use App\Actions\Auth\EndOtherSessions;
use App\Auth\CurrentPasswordConfirmation;
use App\Concerns\PasswordValidationRules;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Security settings')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';



    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {

    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(EndOtherSessions $endOtherSessions, CurrentPasswordConfirmation $currentPassword): void
    {
        $user = Auth::user();

        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string'],
                'password' => $this->passwordRules(),
            ]);

            // Rate limited: a hijacked session must not be able to guess the password.
            $currentPassword->ensure($user, $validated['current_password']);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();

        // Whoever else signed in with the old (possibly temporary) password is signed out.
        $endOtherSessions->handle($user, session()->getId());

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: __('Password updated.'));
    }


}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Security settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Update password')" :subheading="__('Ensure your account is using a long, random password to stay secure')">
        @if (auth()->user()->must_change_password)
            <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6" :heading="__('You are using a temporary password. Please choose a new password to continue.')" data-test="temporary-password-notice" />
        @endif

        <form method="POST" wire:submit="updatePassword" class="mt-6 space-y-6">
            <flux:input
                wire:model="current_password"
                :label="__('Current password')"
                type="password"
                required
                autocomplete="current-password"
                viewable
            />
            <flux:input
                wire:model="password"
                :label="__('New password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />
            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="update-password-button">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>


    </x-pages::settings.layout>

</section>
