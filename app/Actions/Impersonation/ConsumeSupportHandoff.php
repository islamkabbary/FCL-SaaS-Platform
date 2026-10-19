<?php

namespace App\Actions\Impersonation;

use App\Enums\ImpersonationEndReason;
use App\Exceptions\ImpersonationRefused;
use App\Impersonation\Impersonator;
use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Exchanges a support handoff credential for a restricted session on the tenant
 * subdomain (tenant context).
 *
 * The credential is claimed with a single conditional UPDATE (compare-and-set on its
 * hash, the current tenant, "not consumed", "not ended" and "not expired"), so of any
 * number of concurrent or replayed requests at most one succeeds, and its hash is
 * cleared in the same statement. A credential presented to another tenant is burned
 * and its session ended. Eligibility is then checked again before anyone is logged in.
 */
class ConsumeSupportHandoff
{
    public function __construct(protected Impersonator $impersonator) {}

    /**
     * @throws ImpersonationRefused
     */
    public function handle(Request $request, string $token): SupportSession
    {
        $tenant = tenant();
        $hash = hash('sha256', $token);

        $session = $tenant instanceof Tenant && strlen($token) === 64
            ? SupportSession::query()->where('handoff_token_hash', $hash)->first()
            : null;

        if ($session === null) {
            throw ImpersonationRefused::invalidHandoff();
        }

        if ($session->tenant_id !== $tenant->getTenantKey()) {
            $this->impersonator->endRecord($session, ImpersonationEndReason::SessionMismatch);

            throw ImpersonationRefused::invalidHandoff();
        }

        $claimed = SupportSession::query()
            ->whereKey($session->id)
            ->where('handoff_token_hash', $hash)
            ->where('tenant_id', $tenant->getTenantKey())
            ->whereNull('handoff_consumed_at')
            ->whereNull('ended_at')
            ->where('handoff_expires_at', '>', now())
            ->where('expires_at', '>', now())
            ->update([
                'handoff_token_hash' => null,
                'handoff_consumed_at' => now(),
                'handoff_ip' => $request->ip(),
            ]);

        if ($claimed !== 1) {
            throw ImpersonationRefused::invalidHandoff();
        }

        $session->refresh();

        if (($reason = $this->refusal($tenant, $session)) !== null) {
            $this->impersonator->endRecord($session, $reason);

            throw ImpersonationRefused::because(__('Support access could not start: :reason.', ['reason' => $reason->label()]));
        }

        /** @var User $target */
        $target = User::query()->whereKey($session->target_user_id)->firstOrFail();

        $this->impersonator->beginSupport($request, $session, $target);

        return $session;
    }

    /**
     * Re-check, at the moment of entry, everything the session depends on.
     */
    protected function refusal(Tenant $tenant, SupportSession $session): ?ImpersonationEndReason
    {
        $admin = $session->admin;

        if (! config('impersonation.support.enabled')) {
            return ImpersonationEndReason::FeatureDisabled;
        }

        if ($admin === null || $admin->cannot('start-support-session')) {
            return ImpersonationEndReason::ActorUnauthorized;
        }

        if (! $tenant->subscriptionStatus()->allowsAccess()) {
            return ImpersonationEndReason::SubscriptionInactive;
        }

        $target = User::query()->whereKey($session->target_user_id)->first();

        if ($target === null || ! $target->is_active) {
            return ImpersonationEndReason::TargetDeactivated;
        }

        if (! $target->isTenantAdmin() || $target->email !== $session->target_email) {
            return ImpersonationEndReason::TargetIneligible;
        }

        return null;
    }
}
