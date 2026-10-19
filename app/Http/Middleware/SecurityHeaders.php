<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Baseline security headers for every web response.
     *
     * - No framing by other sites (clickjacking).
     * - No MIME sniffing.
     * - Referrers carry no path or query to other origins.
     * - HSTS only on HTTPS requests, so local HTTP development keeps working.
     *
     * A Content Security Policy is not set yet: Livewire, Alpine and Flux need inline
     * scripts and styles, so a useful policy requires nonces (see the readiness report).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()', false);

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000', false);
        }

        return $response;
    }
}
