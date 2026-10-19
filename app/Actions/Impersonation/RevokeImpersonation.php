<?php

namespace App\Actions\Impersonation;

use App\Enums\ImpersonationEndReason;
use App\Exceptions\ImpersonationRefused;
use App\Impersonation\Impersonator;
use App\Models\ImpersonationSession;
use App\Models\PlatformAdmin;
use App\Models\SupportSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Revokes an impersonation from outside the impersonated browser. The record is ended at
 * once; the impersonated browser is torn down on its very next request.
 */
class RevokeImpersonation
{
    public function __construct(protected Impersonator $impersonator) {}

    /**
     * A Platform Admin revokes any pending or active support session (central).
     *
     * @throws AuthorizationException
     * @throws ImpersonationRefused
     */
    public function supportByPlatformAdmin(PlatformAdmin $admin, SupportSession $session): void
    {
        Gate::forUser($admin)->authorize('revoke-support-session');

        $this->revokeSupport($session, $admin);
    }

    /**
     * A Tenant Admin revokes a support session in their own company (tenant context).
     *
     * @throws AuthorizationException
     * @throws ImpersonationRefused
     */
    public function supportByTenantAdmin(User $user, int $sessionId): void
    {
        Gate::forUser($user)->authorize('view-access-history');

        $session = SupportSession::query()
            ->whereKey($sessionId)
            ->where('tenant_id', tenant()?->getTenantKey())
            ->first() ?? throw ImpersonationRefused::because(__('Support session not found.'));

        $this->revokeSupport($session, $user);
    }

    /**
     * A Tenant Admin ends an employee view started by any Tenant Admin of the company.
     *
     * @throws AuthorizationException
     * @throws ImpersonationRefused
     */
    public function employeeByTenantAdmin(User $user, int $sessionId): void
    {
        Gate::forUser($user)->authorize('view-access-history');

        $session = ImpersonationSession::query()->whereKey($sessionId)->first()
            ?? throw ImpersonationRefused::because(__('Impersonation session not found.'));

        if (! $session->isOpen() || ! $this->impersonator->endRecord($session, ImpersonationEndReason::Revoked, $user)) {
            throw ImpersonationRefused::because(__('This session has already ended.'));
        }
    }

    /**
     * @throws ImpersonationRefused
     */
    protected function revokeSupport(SupportSession $session, PlatformAdmin|User $by): void
    {
        if (! $session->isRevocable() || ! $this->impersonator->endRecord($session, ImpersonationEndReason::Revoked, $by)) {
            throw ImpersonationRefused::because(__('This session has already ended.'));
        }
    }
}
