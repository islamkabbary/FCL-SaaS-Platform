<?php

namespace App\Actions\Platform;

use App\Enums\ImpersonationEndReason;
use App\Impersonation\Impersonator;
use App\Models\PlatformAdmin;
use App\Models\SupportSession;
use Illuminate\Support\Facades\DB;

/**
 * Signs a Platform Admin out of their other browsers after a change to their login
 * security (two-factor authentication enabled, disabled or reset), so a session opened
 * by someone else before the change does not survive it. Optionally ends their open
 * support sessions too, when the change weakens their authentication.
 */
class EndPlatformAdminAccess
{
    public function __construct(protected Impersonator $impersonator) {}

    public function handle(PlatformAdmin $admin, ?string $keepSessionId = null, bool $endSupportSessions = false): void
    {
        // Central sessions of authenticated platform requests carry the admin's id.
        if (config('session.driver') === 'database') {
            DB::connection(config('tenancy.database.central_connection'))
                ->table(config('session.table'))
                ->where('user_id', $admin->getKey())
                ->when($keepSessionId !== null, fn ($query) => $query->where('id', '!=', $keepSessionId))
                ->delete();
        }

        if ($endSupportSessions) {
            SupportSession::query()
                ->where('platform_admin_id', $admin->getKey())
                ->open()
                ->get()
                ->each(fn (SupportSession $session): bool => $this->impersonator->endRecord($session, ImpersonationEndReason::ActorUnauthorized));
        }
    }
}
