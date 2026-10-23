<?php

use App\Concerns\ConfirmsCurrentPassword;
use App\Livewire\Actions\Logout;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component {
    use ConfirmsCurrentPassword;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => ['required', 'string'],
        ]);

        $user = Auth::user();

        // Rate limited: a hijacked session must not be able to guess the password.
        $this->ensureCurrentPassword($user, $this->password, 'password');

        // Deleting the last active Tenant Admin would leave the company without an administrator.
        // The active Tenant Admins are locked first (a locking read, see
        // User::activeTenantAdminCount()), so two admins deleting themselves, or a concurrent
        // demotion, cannot both pass the check.
        $deleted = DB::transaction(function () use ($user): bool {
            User::activeTenantAdminCount();

            $current = $user->fresh();

            if ($current === null) {
                return true;
            }

            if ($current->isLastActiveTenantAdmin()) {
                return false;
            }

            $current->delete();

            return true;
        });

        if (! $deleted) {
            throw ValidationException::withMessages([
                'password' => __('The last active Tenant Admin cannot delete their account.'),
            ]);
        }

        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form method="POST" wire:submit="deleteUser" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Are you sure you want to delete your account?') }}</flux:heading>

            <flux:subheading>
                {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
            </flux:subheading>
        </div>

        <flux:input wire:model="password" :label="__('Password')" type="password" viewable />

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="danger" type="submit" data-test="confirm-delete-user-button">
                {{ __('Delete account') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
