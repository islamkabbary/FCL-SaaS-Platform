<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Checks the signed-in user's current password before a sensitive change (password,
 * email, account deletion), with a per-user lockout so a hijacked session cannot try a
 * dictionary of guesses. Scoped per tenant, like the other tenant limiters.
 */
class CurrentPasswordConfirmation
{
    public const MaxAttempts = 5;

    public const LockoutSeconds = 300;

    /**
     * @throws ValidationException
     */
    public function ensure(User $user, string $password, string $field = 'current_password'): void
    {
        $key = 'current-password|'.tenant()?->getTenantKey().'|'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, self::MaxAttempts)) {
            throw ValidationException::withMessages([
                $field => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, self::LockoutSeconds);

            throw ValidationException::withMessages([$field => __('The password is incorrect.')]);
        }

        RateLimiter::clear($key);
    }
}
