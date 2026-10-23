<?php

namespace App\Http\Middleware;

use App\Models\PlatformAdmin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication is required for every Platform Admin (config
 * platform.require_two_factor). Until it is confirmed, a signed-in admin can only reach
 * the enrollment page and log out (alias "platform.two-factor").
 *
 * Without this, a password alone would be enough to enroll an authenticator of the
 * attacker's choosing and then pass the two-factor check of support access.
 */
class EnsurePlatformTwoFactor
{
    /**
     * Routes an admin without two-factor authentication may still use.
     */
    public const EnrollmentRoutes = [
        'platform.account.security',
        'platform.account.two-factor.enable',
        'platform.account.two-factor.confirm',
        'platform.logout',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('platform')->user();

        if (! config('platform.require_two_factor')
            || ! $admin instanceof PlatformAdmin
            || $admin->hasTwoFactorEnabled()
            || $request->routeIs(...self::EnrollmentRoutes)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, __('Set up two-factor authentication to continue.'));
        }

        return redirect()->route('platform.account.security')
            ->with('status', __('Two-factor authentication is required for Platform Admins. Set it up to continue.'));
    }
}
