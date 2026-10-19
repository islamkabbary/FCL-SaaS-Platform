<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantHasActiveSubscription
{
    /**
     * Keep ordinary tenant functionality behind an active subscription.
     *
     * Applied to the operational tenant routes and re-applied by Livewire to every update
     * of components rendered on them (persistent middleware), so neither an existing
     * session nor a Livewire request can bypass it. Legacy tenants (created before
     * subscriptions and not transitioned) keep their access. Sign-in, settings and the
     * subscription pages stay reachable so a Tenant Admin can see why access is limited.
     *
     * Manual suspension and provisioning are enforced earlier by EnsureTenantIsReady and
     * are not affected by the subscription.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();

        if ($tenant instanceof Tenant && ! $tenant->subscriptionStatus()->allowsAccess()) {
            if ($request->hasHeader('X-Livewire') || $request->expectsJson()) {
                abort(403, __('Your company does not have an active subscription.'));
            }

            return redirect()->route('subscription.required');
        }

        return $next($request);
    }
}
