<?php

namespace App\Http\Middleware;

use App\Auth\PlatformTwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorIsAvailable
{
    /**
     * Hide every two-factor page while the feature is switched off (BD-SEC05).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(PlatformTwoFactor::isEnabled(), 404);

        return $next($request);
    }
}
