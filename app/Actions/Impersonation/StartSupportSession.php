<?php

namespace App\Actions\Impersonation;

use App\Enums\PlatformAuditAction;
use App\Enums\TenantRole;
use App\Exceptions\ImpersonationRefused;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SupportAccessStarted;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * Starts a Platform Admin's support session in one tenant, as one of its active Tenant
 * Admins. The caller must have re-verified the admin's password (and authenticator code
 * when required) for this very request.
 *
 * Returns the session and the plaintext handoff credential. The credential is 256 bits
 * from the CSPRNG, valid for at most 60 seconds and usable once; only its SHA-256 hash
 * is stored, and it is never written to a log, the audit trail or a URL.
 */
class StartSupportSession
{
    /**
     * Why support access to the tenant cannot start now, or null.
     */
    public function ineligibility(Tenant $tenant): ?string
    {
        if (! $tenant->isReady()) {
            return __('The company has not finished provisioning.');
        }

        if ($tenant->isSuspended()) {
            return __('The company\'s access is suspended. Support access cannot bypass a suspension.');
        }

        if (! $tenant->subscriptionStatus()->allowsAccess()) {
            return __('The company has no active subscription. Support access cannot bypass subscription restrictions.');
        }

        return null;
    }

    /**
     * The active Tenant Admins of the tenant, read from its database.
     *
     * @return Collection<int, array{id: int, name: string, email: string}>
     */
    public function eligibleTargets(Tenant $tenant): Collection
    {
        return $tenant->run(fn (): Collection => User::query()
            ->active()
            ->role(TenantRole::TenantAdmin->value)
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])
            ->values());
    }

    /**
     * @return array{0: SupportSession, 1: string}
     *
     * @throws AuthorizationException
     * @throws ImpersonationRefused
     */
    public function handle(PlatformAdmin $admin, Tenant $tenant, int $targetUserId, string $reason, ?string $ip = null): array
    {
        Gate::forUser($admin)->authorize('start-support-session');

        // Re-read the lifecycle state instead of trusting the instance from the form.
        $tenant = Tenant::query()->with('domains')->whereKey($tenant->getTenantKey())->firstOrFail();

        if (($ineligibility = $this->ineligibility($tenant)) !== null) {
            throw ImpersonationRefused::because($ineligibility);
        }

        $target = $this->eligibleTargets($tenant)->firstWhere('id', $targetUserId);

        if ($target === null) {
            throw ImpersonationRefused::because(__('The selected account is not an active Tenant Admin of this company.'));
        }

        $token = bin2hex(random_bytes(32));

        $session = DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($admin, $tenant, $target, $reason, $ip, $token): SupportSession {
            $session = SupportSession::query()->create([
                'platform_admin_id' => $admin->id,
                'actor_name' => $admin->name,
                'actor_email' => $admin->email,
                'tenant_id' => $tenant->getTenantKey(),
                'target_user_id' => $target['id'],
                'target_name' => $target['name'],
                'target_email' => $target['email'],
                'reason' => $reason,
                'handoff_token_hash' => hash('sha256', $token),
                'handoff_expires_at' => now()->addSeconds((int) config('impersonation.support.handoff_seconds')),
                'started_at' => now(),
                'expires_at' => now()->addMinutes((int) config('impersonation.support.lifetime_minutes')),
                'started_ip' => $ip,
            ]);

            PlatformAuditLog::record($admin, $tenant, PlatformAuditAction::SupportSessionStarted, newValues: [
                'support_session_id' => $session->id,
                'target' => $session->target_email,
                'expires_at' => $session->expires_at->toDateTimeString(),
            ], reason: $reason);

            return $session;
        });

        // In-app notice to every active Tenant Admin of the company (tenant database).
        $tenant->run(fn () => Notification::send(
            User::query()->active()->role(TenantRole::TenantAdmin->value)->get(),
            new SupportAccessStarted($session),
        ));

        return [$session, $token];
    }
}
