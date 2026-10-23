<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Checks the signed-in user's current password before a sensitive change (password,
 * email, account deletion), with a per-user lockout so a hijacked session cannot try a
 * dictionary of guesses. Scoped per tenant, like the other tenant limiters.
 */
trait ConfirmsCurrentPassword
{
    protected int $maxCurrentPasswordAttempts = 5;

    protected int $currentPasswordLockoutSeconds = 300;

    /**
     * @throws ValidationException
     */
    protected function ensureCurrentPassword(User $user, string $password, string $field = 'current_password'): void
    {
        $key = 'current-password|'.tenant()?->getTenantKey().'|'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, $this->maxCurrentPasswordAttempts)) {
            throw ValidationException::withMessages([
                $field => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, $this->currentPasswordLockoutSeconds);

            throw ValidationException::withMessages([$field => __('The password is incorrect.')]);
        }

        RateLimiter::clear($key);
    }
}
