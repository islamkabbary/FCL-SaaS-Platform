<?php

namespace App\Http\Middleware;

use App\Impersonation\ActiveImpersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses every request, reads included, while impersonating (alias "impersonation.deny").
 *
 * Applied to FCL Operations routes. EnforceImpersonation already refuses these routes
 * (RestrictionPolicy recognises the alias and the "fcl." route names); this middleware
 * keeps the route closed on its own if that recognition were ever missed.
 */
class DenyDuringImpersonation
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $active = ActiveImpersonation::current($request);

        if ($active !== null) {
            $active->recordDenied($request->route()?->getName() ?? '/'.$request->path(), $request->method());

            abort(403, __('This action is not available during an impersonation session.'));
        }

        return $next($request);
    }
}
