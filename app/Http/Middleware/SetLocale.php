<?php

namespace App\Http\Middleware;

use App\Impersonation\ActiveImpersonation;
use App\Models\PlatformAdmin;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const SessionKey = 'locale';

    /**
     * Apply the request's language: the signed-in account's preference, then the session,
     * then the application default.
     *
     * While impersonating, the session comes first: the person acting chooses their own
     * language without changing the preference of the account they act as.
     *
     * The priority list in bootstrap/app.php runs this after ScopeSessions, because it
     * reads the authenticated user from the session.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolveLocale($request));

        return $next($request);
    }

    /**
     * The account signed in for the current application side: tenant users on a tenant,
     * Platform Admins on the central domain.
     */
    public static function authenticatedAccount(): User|PlatformAdmin|null
    {
        $account = Auth::guard(tenancy()->initialized ? 'web' : 'platform')->user();

        return $account instanceof User || $account instanceof PlatformAdmin ? $account : null;
    }

    protected function resolveLocale(Request $request): string
    {
        $sessionLocale = $request->hasSession() ? $request->session()->get(self::SessionKey) : null;
        $preferredLocale = self::authenticatedAccount()?->locale;

        $candidates = ActiveImpersonation::current($request) === null
            ? [$preferredLocale, $sessionLocale]
            : [$sessionLocale, $preferredLocale];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && array_key_exists($candidate, config()->array('app.supported_locales'))) {
                return $candidate;
            }
        }

        return config()->string('app.locale');
    }
}
