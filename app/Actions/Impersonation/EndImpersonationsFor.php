<?php

namespace App\Actions\Impersonation;

use App\Enums\ImpersonationEndReason;
use App\Impersonation\Impersonator;
use App\Models\ImpersonationSession;
use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\User;

/**
 * Ends open impersonations immediately when the condition they depend on changes, so
 * the history shows the real cause. (EnforceImpersonation would also end them on the
 * next request; this keeps the records accurate when no further request comes.)
 */
class EndImpersonationsFor
{
    public function __construct(protected Impersonator $impersonator) {}

    /**
     * Central: the tenant was suspended. Tenant-local employee views end on their next request.
     */
    public function suspendedTenant(Tenant $tenant): void
    {
        SupportSession::query()
            ->where('tenant_id', $tenant->getTenantKey())
            ->open()
            ->each(fn (SupportSession $session) => $this->impersonator->endRecord($session, ImpersonationEndReason::TenantSuspended));
    }

    /**
     * Tenant context: a user was deactivated, as the target or the initiator of a session.
     */
    public function deactivatedUser(User $user): void
    {
        ImpersonationSession::query()->open()->where('target_user_id', $user->id)
            ->each(fn (ImpersonationSession $session) => $this->impersonator->endRecord($session, ImpersonationEndReason::TargetDeactivated));

        ImpersonationSession::query()->open()->where('impersonator_user_id', $user->id)
            ->each(fn (ImpersonationSession $session) => $this->impersonator->endRecord($session, ImpersonationEndReason::ActorUnauthorized));

        SupportSession::query()
            ->where('tenant_id', tenant()?->getTenantKey())
            ->where('target_user_id', $user->id)
            ->open()
            ->each(fn (SupportSession $session) => $this->impersonator->endRecord($session, ImpersonationEndReason::TargetDeactivated));
    }
}
