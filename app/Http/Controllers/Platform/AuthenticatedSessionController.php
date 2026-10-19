<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Session key of a login waiting for the second factor.
     */
    public const PendingTwoFactorKey = 'platform.two_factor.pending';

    /**
     * Minutes a password-verified login may wait for its second factor.
     */
    public const PendingTwoFactorMinutes = 5;

    /**
     * Show the platform admin login form.
     */
    public function create(): View
    {
        return view('platform.auth.login');
    }

    /**
     * Authenticate a platform admin. With confirmed two-factor authentication, the
     * password only opens the second step; the admin is not logged in yet.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $admin = $request->authenticate();

        $request->session()->regenerate();

        if ($admin->hasTwoFactorEnabled()) {
            $request->session()->put(self::PendingTwoFactorKey, [
                'id' => $admin->id,
                'expires_at' => now()->addMinutes(self::PendingTwoFactorMinutes)->getTimestamp(),
            ]);

            return redirect()->route('platform.two-factor.challenge');
        }

        Auth::guard('platform')->login($admin);

        return redirect()->intended(route('platform.dashboard', absolute: false));
    }

    /**
     * Log the platform admin out.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('platform')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }
}
