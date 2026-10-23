<?php

namespace App\Console\Commands;

use App\Actions\Platform\EndPlatformAdminAccess;
use App\Enums\PlatformAuditAction;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;

#[Signature('platform:reset-two-factor {email : The Platform Admin\'s email address} {--force : Skip the confirmation}')]
#[Description('Remove a Platform Admin\'s two-factor authentication (lost authenticator and recovery codes)')]
class ResetPlatformAdminTwoFactor extends Command
{
    /**
     * Recovery procedure of last resort, for operators with server access. The admin can
     * then log in with their password and must set up an authenticator again before
     * starting support sessions. The reset is recorded in the central audit trail.
     */
    public function handle(EndPlatformAdminAccess $endAccess): int
    {
        $argument = $this->argument('email');
        $email = Str::lower(is_string($argument) ? $argument : '');
        $admin = PlatformAdmin::query()->where('email', $email)->first();

        if ($admin === null) {
            $this->components->error('No Platform Admin has that email address.');

            return self::FAILURE;
        }

        if ($admin->two_factor_secret === null) {
            $this->components->info('Two-factor authentication is not set up for this admin.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! confirm("Remove two-factor authentication of {$admin->email}?", default: false)) {
            return self::FAILURE;
        }

        $admin->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestep' => null,
        ])->save();

        PlatformAuditLog::recordSystem(null, PlatformAuditAction::TwoFactorReset, ['admin' => $admin->email], 'Reset from the command line');

        // Whoever holds the lost authenticator must not keep a signed-in browser or support session.
        $endAccess->handle($admin, endSupportSessions: true);

        $this->components->info("Two-factor authentication of {$admin->email} was removed.");

        return self::SUCCESS;
    }
}
