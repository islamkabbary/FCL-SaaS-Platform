<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantIsReady
{
    /**
     * Hide tenants that have not finished provisioning (or failed to).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();

        abort_unless($tenant instanceof Tenant && $tenant->isReady(), 404);

        return $next($request);
    }
}
