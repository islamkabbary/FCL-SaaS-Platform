<?php

use App\Auth\CurrentPasswordConfirmation;
use App\Concerns\ProfileValidationRules;
use App\Models\Tenant;
use App\Notifications\EmailAddressChanged;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component {
    use ProfileValidationRules;

    public string $name = '';
    public string $email = '';
    public string $current_password = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(CurrentPasswordConfirmation $currentPassword): void
    {
        $user = Auth::user();
        $oldEmail = $user->email;

        $this->email = Str::lower(trim($this->email));
        $validated = $this->validate($this->profileRules($user->id));

        // The login email receives the password reset link, so changing it needs the
        // current password (someone at an unlocked session could otherwise take the account).
        if ($validated['email'] !== $oldEmail) {
            $this->validate(['current_password' => ['required', 'string']]);
            $currentPassword->ensure($user, $this->current_password);
        }

        $user->fill($validated);

        $emailChanged = $user->isDirty('email');

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->reset('current_password');

        if ($emailChanged) {
            $tenant = tenant();

            Notification::route('mail', $oldEmail)
                ->notify(new EmailAddressChanged($oldEmail, $user->email, $user->name, $tenant instanceof Tenant ? $tenant->name : null));
        }

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

            </div>

            <flux:input
                wire:model="current_password"
                :label="__('Current password')"
                :description="__('Required only when you change your email address.')"
                type="password"
                autocomplete="current-password"
            />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

            </div>
        </form>

            <livewire:pages::settings.delete-user-form />
    </x-pages::settings.layout>
</section>
