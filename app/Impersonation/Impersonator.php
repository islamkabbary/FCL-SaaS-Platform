<?php

namespace App\Impersonation;

use App\Enums\ImpersonationEndReason;
use App\Enums\ImpersonationKind;
use App\Enums\ImpersonationStatus;
use App\Enums\PlatformAuditAction;
use App\Models\ImpersonationSession;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Switches the tenant session into and out of an impersonated identity, and decides on
 * every request whether an impersonation may continue.
 *
 * The tenant session holds a marker (kind, session id, tenant, target, impersonator).
 * The marker only points at the authoritative record (support_sessions in the central
 * database or impersonation_sessions in the tenant database), which is re-read on every
 * request; the marker itself never grants anything.
 */
class Impersonator
{
    public const SessionKey = 'impersonation';

    public function __construct(protected EmployeeImpersonationRules $rules) {}

    /**
     * Start a support session in this browser: any previous tenant login is discarded,
     * the session id is renewed (no fixation) and the Tenant Admin is logged in without
     * "remember me", so nothing about the target's account is changed.
     */
    public function beginSupport(Request $request, SupportSession $session, User $target): void
    {
        // logout() would rotate the remember-me token of whoever was signed in here.
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();

        Auth::guard('web')->login($target);
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        $request->session()->put(self::SessionKey, [
            'kind' => ImpersonationKind::Support->value,
            'id' => $session->id,
            'tenant' => $session->tenant_id,
            'target' => $target->id,
            'impersonator' => null,
        ]);
    }

    /**
     * Switch a Tenant Admin's own session to the employee's identity. The Tenant Admin's
     * other sessions and the employee's own sessions are not touched.
     */
    public function beginEmployee(Request $request, ImpersonationSession $session, User $admin, User $employee): void
    {
        Auth::guard('web')->login($employee);
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        $request->session()->put(self::SessionKey, [
            'kind' => ImpersonationKind::Employee->value,
            'id' => $session->id,
            'tenant' => tenant()?->getTenantKey(),
            'target' => $employee->id,
            'impersonator' => $admin->id,
        ]);
    }

    /**
     * Find the record a session marker points to.
     *
     * @param  array<string, mixed>  $marker
     */
    public function resolve(array $marker): SupportSession|ImpersonationSession|null
    {
        $id = $marker['id'] ?? null;

        if (! is_int($id)) {
            return null;
        }

        return match (ImpersonationKind::tryFrom((string) ($marker['kind'] ?? ''))) {
            ImpersonationKind::Support => SupportSession::query()->whereKey($id)->first(),
            ImpersonationKind::Employee => ImpersonationSession::query()->whereKey($id)->first(),
            default => null,
        };
    }

    /**
     * Why the impersonation must stop now, or null if it may continue.
     *
     * @param  array<string, mixed>  $marker
     */
    public function terminationReason(array $marker, SupportSession|ImpersonationSession $session, ?User $user): ?ImpersonationEndReason
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant
            || ($marker['tenant'] ?? null) !== $tenant->getTenantKey()
            || $user === null
            || $user->getKey() !== $session->targetUserId()
            || ($marker['target'] ?? null) !== $session->targetUserId()) {
            return ImpersonationEndReason::SessionMismatch;
        }

        if ($session->ended_at !== null) {
            return $session->end_reason;
        }

        if ($session->status() !== ImpersonationStatus::Active) {
            return ImpersonationEndReason::Expired;
        }

        if ($session instanceof SupportSession) {
            if ($session->tenant_id !== $tenant->getTenantKey()) {
                return ImpersonationEndReason::SessionMismatch;
            }

            if (! config('impersonation.support.enabled')) {
                return ImpersonationEndReason::FeatureDisabled;
            }

            $admin = $session->admin;

            if ($admin === null || Gate::forUser($admin)->denies('start-support-session')) {
                return ImpersonationEndReason::ActorUnauthorized;
            }

            $targetEligible = static fn (User $target): bool => $target->isTenantAdmin();
        } else {
            if (! config('impersonation.employee.enabled')) {
                return ImpersonationEndReason::FeatureDisabled;
            }

            $impersonator = User::query()->whereKey($session->impersonator_user_id)->first();

            if (($marker['impersonator'] ?? null) !== $session->impersonator_user_id || $impersonator === null || ! $impersonator->is_active || ! $impersonator->isTenantAdmin()) {
                return ImpersonationEndReason::ActorUnauthorized;
            }

            $targetEligible = fn (User $target): bool => $this->rules->denialReason($impersonator, $target) === null;
        }

        if ($this->suspendedSince($tenant, $session)) {
            return ImpersonationEndReason::TenantSuspended;
        }

        if (! $tenant->subscriptionStatus()->allowsAccess()) {
            return ImpersonationEndReason::SubscriptionInactive;
        }

        if (! $user->is_active) {
            return ImpersonationEndReason::TargetDeactivated;
        }

        return $targetEligible($user) ? null : ImpersonationEndReason::TargetIneligible;
    }

    /**
     * End the impersonation and leave the browser in a safe state: a support session is
     * logged out of the tenant entirely; an employee view returns to the Tenant Admin's
     * own identity when they are still an active Tenant Admin, otherwise it is logged out.
     *
     * Returns the restored Tenant Admin, if any.
     */
    public function terminate(Request $request, SupportSession|ImpersonationSession|null $session, ImpersonationEndReason $reason): ?User
    {
        $marker = $request->session()->get(self::SessionKey);

        if ($session !== null) {
            $this->endRecord($session, $reason);
        }

        $request->attributes->remove(ActiveImpersonation::RequestAttribute);
        $request->session()->forget(self::SessionKey);

        $restored = null;

        if ($session instanceof ImpersonationSession && is_array($marker) && ($marker['impersonator'] ?? null) === $session->impersonator_user_id) {
            $admin = User::query()->whereKey($session->impersonator_user_id)->first();

            if ($admin !== null && $admin->is_active && $admin->isTenantAdmin()) {
                Auth::guard('web')->login($admin);
                $request->session()->regenerate();
                $request->session()->regenerateToken();

                $restored = $admin;
            }
        }

        if ($restored === null) {
            $this->signOutCurrentDevice($request);
        }

        return $restored;
    }

    /**
     * Sign the impersonated identity out of this browser only.
     *
     * SessionGuard::logout() also rotates the user's remember-me token, which would sign
     * the real account holder out of "remember me" on all their devices whenever a support
     * session or employee view ends. logoutCurrentDevice() leaves the account untouched.
     */
    public function signOutCurrentDevice(Request $request): void
    {
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Mark the record ended (once) and, for support sessions, audit it centrally with
     * the identity that actually ended it.
     */
    public function endRecord(SupportSession|ImpersonationSession $session, ImpersonationEndReason $reason, PlatformAdmin|User|null $endedBy = null): bool
    {
        if ($session instanceof ImpersonationSession) {
            $endedByEmail = $endedBy->email
                ?? (in_array($reason, [ImpersonationEndReason::EndedByActor, ImpersonationEndReason::LoggedOut], true) ? $session->impersonator_email : null);

            return $session->end($reason, $endedByEmail);
        }

        // Ending by the initiator from the tenant side is attributed to the initiating Platform Admin.
        if ($endedBy === null && in_array($reason, [ImpersonationEndReason::EndedByActor, ImpersonationEndReason::LoggedOut], true)) {
            $endedBy = $session->admin;
        }

        $endedByType = match (true) {
            $endedBy instanceof PlatformAdmin => SupportSession::EndedByPlatformAdmin,
            $endedBy instanceof User => SupportSession::EndedByTenantUser,
            default => SupportSession::EndedBySystem,
        };

        if (! $session->end($reason, $endedBy?->email, ['ended_by_type' => $endedByType, 'handoff_token_hash' => null])) {
            return false;
        }

        $action = $reason === ImpersonationEndReason::Revoked ? PlatformAuditAction::SupportSessionRevoked : PlatformAuditAction::SupportSessionEnded;
        $values = ['support_session_id' => $session->id, 'target' => $session->target_email, 'end_reason' => $reason->value];
        $tenant = Tenant::query()->whereKey($session->tenant_id)->firstOrFail();

        match (true) {
            $endedBy instanceof PlatformAdmin => PlatformAuditLog::record($endedBy, $tenant, $action, newValues: $values, reason: $reason->label()),
            $endedBy instanceof User => PlatformAuditLog::recordForTenantUser($endedBy, $tenant, $action, $values, $reason->label()),
            default => PlatformAuditLog::recordSystem($tenant, $action, $values, $reason->label()),
        };

        return true;
    }

    /**
     * Whether the tenant was suspended after the session started (it may have been
     * reactivated since; suspension still ends the session).
     */
    protected function suspendedSince(Tenant $tenant, SupportSession|ImpersonationSession $session): bool
    {
        return $tenant->isSuspended() || PlatformAuditLog::query()
            ->where('tenant_id', $tenant->getTenantKey())
            ->where('action', PlatformAuditAction::TenantSuspended)
            ->where('created_at', '>=', $session->started_at)
            ->exists();
    }
}
