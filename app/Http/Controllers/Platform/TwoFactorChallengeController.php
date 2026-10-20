<?php

namespace App\Http\Controllers\Platform;

use App\Auth\PlatformTwoFactor;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Second login step for Platform Admins with confirmed two-factor authentication.
 */
class TwoFactorChallengeController extends Controller
{
    public const MaxAttempts = 5;

    public function create(Request $request): View|RedirectResponse
    {
        if ($this->pendingAdmin($request) === null) {
            return redirect()->route('platform.login');
        }

        return view('platform.auth.two-factor-challenge');
    }

    /**
     * Verify an authenticator or recovery code. After too many wrong codes the pending
     * login is discarded, and no code is accepted for the account until the lockout
     * window has passed, even after the password is entered again.
     *
     * @throws ValidationException
     */
    public function store(Request $request, PlatformTwoFactor $twoFactor): RedirectResponse
    {
        $request->validate([
            'code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:100'],
        ]);

        $admin = $this->pendingAdmin($request);

        if ($admin === null) {
            return redirect()->route('platform.login')->withErrors(['email' => __('Please log in again.')]);
        }

        $key = 'platform-two-factor|'.$admin->id;

        // Checked before any code is verified: the lockout belongs to the account, so signing
        // in with the password again does not give a fresh set of attempts.
        if (RateLimiter::tooManyAttempts($key, self::MaxAttempts)) {
            $request->session()->forget(AuthenticatedSessionController::PendingTwoFactorKey);

            return redirect()->route('platform.login')->withErrors(['email' => __('Too many invalid codes. Please try again in :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($key),
            ])]);
        }

        if (! $twoFactor->verifyCodeOrRecoveryCode($admin, $request->input('code'), $request->input('recovery_code'))) {
            if (RateLimiter::hit($key, 300) >= self::MaxAttempts) {
                $request->session()->forget(AuthenticatedSessionController::PendingTwoFactorKey);

                return redirect()->route('platform.login')->withErrors(['email' => __('Too many invalid codes. Please try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($key),
                ])]);
            }

            throw ValidationException::withMessages(['code' => __('The provided two-factor authentication code was invalid.')]);
        }

        RateLimiter::clear($key);
        $request->session()->forget(AuthenticatedSessionController::PendingTwoFactorKey);

        Auth::guard('platform')->login($admin);
        $request->session()->regenerate();

        return redirect()->intended(route('platform.dashboard', absolute: false));
    }

    protected function pendingAdmin(Request $request): ?PlatformAdmin
    {
        $pending = $request->session()->get(AuthenticatedSessionController::PendingTwoFactorKey);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        $admin = PlatformAdmin::query()->whereKey($pending['id'] ?? null)->first();

        return $admin?->hasTwoFactorEnabled() ? $admin : null;
    }
}
