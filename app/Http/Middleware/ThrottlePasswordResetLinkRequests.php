<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ThrottlePasswordResetLinkRequests
{
    public const MaxPerMinute = 5;

    /**
     * Limit password reset link requests per client IP within a company.
     *
     * Fortify registers the route without any limiter; the password broker only throttles
     * repeated requests for one existing address, so cycling through addresses (to send
     * mail to many users, or to probe for accounts) was unlimited. Runs in the "tenant"
     * group, which Fortify's routes use, and only acts on the reset link route.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws ValidationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('password.email')) {
            return $next($request);
        }

        $key = 'password-reset-link|'.tenant()?->getTenantKey().'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MaxPerMinute)) {
            throw ValidationException::withMessages([
                'email' => __('Too many password reset requests. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
