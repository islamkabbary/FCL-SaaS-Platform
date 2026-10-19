<?php

namespace App\Auth;

use App\Models\PlatformAdmin;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor authentication for Platform Admins (central "platform" guard).
 *
 * Fortify's two-factor feature is bound to the tenant "web" guard and its routes run in
 * tenant context, so the central guard uses the same building blocks (google2fa, the
 * bacon QR renderer, Fortify's recovery code format) directly. Unlike Fortify's default,
 * replay protection is per admin and stored in the database: an accepted code's time
 * step is recorded with a compare-and-set, so the same code cannot be used twice, even
 * by two simultaneous requests.
 */
class PlatformTwoFactor
{
    public const RecoveryCodeCount = 8;

    public function __construct(protected Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function qrCodeSvg(PlatformAdmin $admin, string $secret): string
    {
        $url = $this->engine->getQRCodeUrl(config('app.name').' Platform', $admin->email, $secret);

        $svg = (new Writer(new ImageRenderer(
            new RendererStyle(192, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(45, 55, 72))),
            new SvgImageBackEnd,
        )))->writeString($url);

        return trim(substr($svg, (int) strpos($svg, "\n") + 1));
    }

    /**
     * Verify a 6-digit code against the admin's secret (or the given pending secret
     * during enrollment) and consume its time step.
     */
    public function verify(PlatformAdmin $admin, string $code, ?string $secret = null): bool
    {
        $secret ??= $admin->two_factor_secret;
        $code = (string) preg_replace('/\s+/', '', $code);

        if ($secret === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $timestep = $this->engine->verifyKeyNewer($secret, $code, (int) $admin->two_factor_last_timestep);

        if (! is_int($timestep)) {
            return false;
        }

        $consumed = PlatformAdmin::query()
            ->whereKey($admin->id)
            ->where(fn ($query) => $query->whereNull('two_factor_last_timestep')->orWhere('two_factor_last_timestep', '<', $timestep))
            ->update(['two_factor_last_timestep' => $timestep]) === 1;

        $admin->two_factor_last_timestep = $consumed ? $timestep : $admin->two_factor_last_timestep;

        return $consumed;
    }

    /**
     * Use (and remove) one recovery code. Codes are compared in constant time.
     */
    public function useRecoveryCode(PlatformAdmin $admin, string $code): bool
    {
        $code = trim($code);

        if ($code === '') {
            return false;
        }

        return DB::connection($admin->getConnectionName())->transaction(function () use ($admin, $code): bool {
            $locked = PlatformAdmin::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $codes = $locked->two_factor_recovery_codes ?? [];

            foreach ($codes as $index => $candidate) {
                if (hash_equals($candidate, $code)) {
                    unset($codes[$index]);
                    $locked->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                    $admin->two_factor_recovery_codes = array_values($codes);

                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Accept either an authenticator code or a recovery code.
     */
    public function verifyCodeOrRecoveryCode(PlatformAdmin $admin, ?string $code, ?string $recoveryCode): bool
    {
        if ($code !== null && $code !== '') {
            return $this->verify($admin, $code);
        }

        return $recoveryCode !== null && $this->useRecoveryCode($admin, $recoveryCode);
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        return array_map(fn (): string => RecoveryCode::generate(), range(1, self::RecoveryCodeCount));
    }
}
