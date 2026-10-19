<?php

namespace App\Http\Middleware;

use App\Enums\ImpersonationEndReason;
use App\Impersonation\ActiveImpersonation;
use App\Impersonation\Impersonator;
use App\Impersonation\RestrictionPolicy;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceImpersonation
{
    public function __construct(protected Impersonator $impersonator) {}

    /**
     * Verify an impersonated session on every tenant request, before anything else uses
     * the authenticated user.
     *
     * Runs in the "tenant" middleware group (page requests, form posts, Fortify routes and
     * Livewire updates alike). The session marker is checked against its authoritative
     * record each time; an impersonation that expired, was ended or revoked, or whose
     * conditions no longer hold is torn down before the request can do anything. A valid
     * one is published on the request attributes for the Livewire and Gate layers, and
     * non-GET requests outside the allowlist are refused and recorded.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $marker = $request->hasSession() ? $request->session()->get(Impersonator::SessionKey) : null;

        if (! is_array($marker)) {
            return $next($request);
        }

        $session = $this->impersonator->resolve($marker);

        if ($session === null) {
            $this->impersonator->terminate($request, null, ImpersonationEndReason::SessionMismatch);

            return $this->endedResponse($request, ImpersonationEndReason::SessionMismatch, false);
        }

        $user = Auth::guard('web')->user();
        $reason = $this->impersonator->terminationReason($marker, $session, $user instanceof User ? $user : null);

        if ($reason !== null) {
            $restored = $this->impersonator->terminate($request, $session, $reason);

            return $this->endedResponse($request, $reason, $restored !== null);
        }

        $active = new ActiveImpersonation($session);
        $request->attributes->set(ActiveImpersonation::RequestAttribute, $active);

        if ($request->routeIs('logout')) {
            // Logging out ends the impersonation; the logout itself then proceeds normally.
            $this->impersonator->endRecord($session, ImpersonationEndReason::LoggedOut);
            $request->attributes->remove(ActiveImpersonation::RequestAttribute);
            $request->session()->forget(Impersonator::SessionKey);

            return $next($request);
        }

        if (! RestrictionPolicy::allowsRequest($request)) {
            $active->recordDenied($request->route()?->getName() ?? '/'.$request->path(), $request->method());

            abort(403, __('This action is not available during an impersonation session.'));
        }

        if ($request->isMethod('GET') && ! $request->hasHeader('X-Livewire')) {
            $active->recordViewed($request->route()?->getName() ?? '/'.$request->path());
        }

        return $next($request);
    }

    protected function endedResponse(Request $request, ImpersonationEndReason $reason, bool $restored): Response
    {
        $message = __('The impersonation session has ended: :reason.', ['reason' => $reason->label()]);

        // Never let the request that discovered the end continue, under either identity.
        if ($request->hasHeader('X-Livewire') || $request->expectsJson()) {
            abort(403, $message);
        }

        return redirect()->route($restored ? 'employees.index' : 'login')->with('status', $message);
    }
}
