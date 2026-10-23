<?php

namespace App\Http\Requests\Platform;

use App\Auth\DummyPasswordCheck;
use App\Models\PlatformAdmin;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Maximum failed attempts per email and IP address before being locked out.
     */
    protected const MaxAttempts = 5;

    /**
     * Maximum failed attempts per account from any number of IP addresses, per hour, so a
     * distributed guess against one Platform Admin is bounded too.
     */
    protected const MaxAccountAttempts = 20;

    protected const AccountDecaySeconds = 3600;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Verify the platform admin's credentials against the central database.
     *
     * Does not log in: the controller logs in directly, or first asks for the second
     * factor when the admin has confirmed two-factor authentication. Platform sessions
     * are never remembered ("remember me" is not offered for this privileged guard).
     *
     * @throws ValidationException
     */
    public function authenticate(): PlatformAdmin
    {
        $this->ensureIsNotRateLimited();

        $credentials = $this->only('email', 'password');
        $provider = Auth::guard('platform')->getProvider();
        $admin = $provider->retrieveByCredentials(['email' => $credentials['email']]);

        if (! $admin instanceof PlatformAdmin) {
            // Same work as a wrong password, so timing does not reveal unknown accounts.
            DummyPasswordCheck::run((string) $credentials['password']);
        }

        if (! $admin instanceof PlatformAdmin || ! $provider->validateCredentials($admin, $credentials)) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->accountThrottleKey(), self::AccountDecaySeconds);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $provider->rehashPasswordIfRequired($admin, $credentials);

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->accountThrottleKey());

        return $admin;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(): void
    {
        $key = match (true) {
            RateLimiter::tooManyAttempts($this->throttleKey(), self::MaxAttempts) => $this->throttleKey(),
            RateLimiter::tooManyAttempts($this->accountThrottleKey(), self::MaxAccountAttempts) => $this->accountThrottleKey(),
            default => null,
        };

        if ($key === null) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    protected function throttleKey(): string
    {
        return 'platform-login|'.Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    /**
     * Get the rate limiting key of the account, whatever the IP address.
     */
    protected function accountThrottleKey(): string
    {
        return 'platform-login-account|'.Str::transliterate(Str::lower($this->string('email')));
    }
}
