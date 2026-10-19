<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Impersonation\RevokeImpersonation;
use App\Actions\Impersonation\StartSupportSession;
use App\Auth\PlatformTwoFactor;
use App\Enums\ImpersonationStatus;
use App\Exceptions\ImpersonationRefused;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\SupportSession;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Platform support access to a tenant: start (with fresh re-authentication), the
 * handoff to the tenant subdomain, history and revocation.
 */
class SupportSessionController extends Controller
{
    public const MaxReauthenticationAttempts = 5;

    public function index(Request $request): View
    {
        $status = ImpersonationStatus::tryFrom($request->string('status')->toString());

        $sessions = SupportSession::query()
            ->with('tenant.domains')
            ->when($request->filled('tenant'), fn ($query) => $query->where('tenant_id', $request->string('tenant')->toString()))
            ->latest('started_at')
            ->latest('id')
            ->limit(200)
            ->get()
            // The status is derived from timestamps, so it is filtered after loading.
            ->when($status, fn ($sessions, ImpersonationStatus $status) => $sessions->filter(fn (SupportSession $session) => $session->status() === $status));

        return view('platform.support-sessions.index', [
            'sessions' => $sessions,
            'status' => $status,
        ]);
    }

    public function show(SupportSession $supportSession): View
    {
        return view('platform.support-sessions.show', [
            'session' => $supportSession->load('tenant.domains'),
            'events' => $supportSession->events()->latest('created_at')->latest('id')->limit(200)->get(),
        ]);
    }

    public function create(Request $request, Tenant $tenant, StartSupportSession $startSupportSession): View
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');
        $ineligibility = $startSupportSession->ineligibility($tenant);

        return view('platform.support-sessions.create', [
            'tenant' => $tenant->load('domains'),
            'ineligibility' => $ineligibility,
            'targets' => $ineligibility === null ? $startSupportSession->eligibleTargets($tenant) : collect(),
            'requiresTwoFactor' => (bool) config('impersonation.support.require_two_factor'),
            'hasTwoFactor' => $admin->hasTwoFactorEnabled(),
            'lifetime' => (int) config('impersonation.support.lifetime_minutes'),
        ]);
    }

    /**
     * Start the session and hand the browser over to the tenant subdomain.
     *
     * The handoff credential travels in the body of an auto-submitted POST form, never
     * in a URL, so it does not reach browser history, server access logs or Referer
     * headers. The response is not cacheable and sends no referrer.
     */
    public function store(Request $request, Tenant $tenant, StartSupportSession $startSupportSession, PlatformTwoFactor $twoFactor): Response|RedirectResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');
        $requiresTwoFactor = (bool) config('impersonation.support.require_two_factor');

        $validated = $request->validate([
            'target_user_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'password' => ['required', 'string'],
            'code' => [$requiresTwoFactor ? 'required' : 'nullable', 'string', 'max:20'],
        ]);

        $this->reauthenticate($admin, $validated['password'], $requiresTwoFactor ? $validated['code'] : null, $requiresTwoFactor, $twoFactor);

        try {
            [$session, $token] = $startSupportSession->handle($admin, $tenant, (int) $validated['target_user_id'], trim($validated['reason']), $request->ip());
        } catch (ImpersonationRefused $exception) {
            return back()->withInput($request->except('password', 'code'))->with('error', $exception->getMessage());
        }

        return response()
            ->view('platform.support-sessions.handoff', [
                'session' => $session,
                'tenant' => $tenant->load('domains'),
                'action' => $tenant->url('support/handoff'),
                'token' => $token,
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function revoke(Request $request, SupportSession $supportSession, RevokeImpersonation $revoke): RedirectResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        try {
            $revoke->supportByPlatformAdmin($admin, $supportSession);
        } catch (ImpersonationRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('Support session revoked. The tenant session ends on its next request.'));
    }

    /**
     * Fresh re-authentication for this request: the admin's own password and, when
     * required, a current authenticator code (rate limited per admin).
     *
     * @throws ValidationException
     */
    protected function reauthenticate(PlatformAdmin $admin, string $password, ?string $code, bool $requiresTwoFactor, PlatformTwoFactor $twoFactor): void
    {
        $key = 'support-reauth|'.$admin->id;

        if (RateLimiter::tooManyAttempts($key, self::MaxReauthenticationAttempts)) {
            throw ValidationException::withMessages([
                'password' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! Hash::check($password, $admin->password)) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['password' => __('The password is incorrect.')]);
        }

        if ($requiresTwoFactor) {
            if (! $admin->hasTwoFactorEnabled()) {
                throw ValidationException::withMessages(['code' => __('Set up two-factor authentication for your account before starting support sessions.')]);
            }

            if (! $twoFactor->verify($admin, (string) $code)) {
                RateLimiter::hit($key, 300);

                throw ValidationException::withMessages(['code' => __('The provided two-factor authentication code was invalid.')]);
            }
        }

        RateLimiter::clear($key);
    }
}
