<?php

namespace App\Http\Middleware;

use App\Enums\ImpersonationEndReason;
use App\Impersonation\ActiveImpersonation;
use App\Impersonation\Impersonator;
use App\Impersonation\RestrictionPolicy;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LogoutResponse;
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
            // Logging out ends the impersonation and signs this browser out here, with the
            // usual logout response. Fortify's logout would call SessionGuard::logout(), which
            // rotates the impersonated user's remember-me token on all their devices.
            $this->impersonator->endRecord($session, ImpersonationEndReason::LoggedOut);
            $request->attributes->remove(ActiveImpersonation::RequestAttribute);
            $request->session()->forget(Impersonator::SessionKey);
            $this->impersonator->signOutCurrentDevice($request);

            return app(LogoutResponse::class)->toResponse($request);
        }

        if (! RestrictionPolicy::allowsRequest($request)) {
            $active->recordDenied($request->route()?->getName() ?? '/'.$request->path(), $request->method());

            abort(403, __('This action is not available during an impersonation session.'));
        }

        // Recorded after the request was handled, so the history shows what was actually
        // seen: a request refused further on (403, e.g. by a "can:" check, also one that
        // Livewire re-applies to an update) is recorded as denied, once. Every successful
        // GET is recorded as viewed, whatever its headers: Livewire's own updates are POST
        // requests, so a client-chosen header cannot hide a page view.
        $response = $next($request);
        $action = $request->route()?->getName() ?? '/'.$request->path();

        if ($response->getStatusCode() === Response::HTTP_FORBIDDEN) {
            if (! $active->hasRecordedDenial($request)) {
                $active->recordDenied($this->deniedAction($request, $action), $request->method());
            }
        } elseif ($request->isMethod('GET') && $response->getStatusCode() < 400) {
            $active->recordViewed($action);
        }

        return $response;
    }

    /**
     * The refused action. A Livewire update is named after its components (as in
     * "livewire.update: pages::fcl.parties.index"), so the history shows which page was
     * refused; Livewire has verified the snapshots before it re-applies a page's checks.
     */
    protected function deniedAction(Request $request, string $action): string
    {
        if ($action !== 'livewire.update') {
            return $action;
        }

        $names = collect(Arr::wrap($request->input('components')))
            ->map(fn (mixed $component): mixed => is_array($component) && is_string($component['snapshot'] ?? null)
                ? (json_decode($component['snapshot'], true)['memo']['name'] ?? null)
                : null)
            ->filter(fn (mixed $name): bool => is_string($name))
            ->unique()
            ->implode(', ');

        return $names === '' ? $action : $action.': '.$names;
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
