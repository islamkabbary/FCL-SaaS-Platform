<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantIsReady
{
    /**
     * Hide tenants that have not finished provisioning (or failed to), and refuse
     * every request to a suspended tenant.
     *
     * Runs in the "tenant" group right after identification and before the session
     * starts, so it covers page requests, Livewire updates and the authentication
     * routes alike, and an existing session or "remember me" cookie cannot get past it.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();

        abort_unless($tenant instanceof Tenant, 404);

        // stancl keeps the already initialized tenant instance when the same tenant is
        // identified again in a long-lived process (Octane, the test client), so read the
        // lifecycle state from the central database instead of trusting that instance.
        $tenant->refresh();

        abort_unless($tenant->isReady(), 404);

        abort_if($tenant->isSuspended(), 403, __('Access to this company account has been suspended. Please contact your platform administrator.'));

        return $next($request);
    }
}
