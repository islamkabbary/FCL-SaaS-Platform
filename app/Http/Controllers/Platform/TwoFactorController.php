<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\EndPlatformAdminAccess;
use App\Auth\PlatformTwoFactor;
use App\Enums\PlatformAuditAction;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A Platform Admin's own two-factor authentication: enroll, confirm, regenerate
 * recovery codes, disable. Every change requires the current password; disabling and
 * regenerating also require a current code. Recovery codes are shown once, in the
 * response itself (never flashed to the session), with caching disabled.
 */
class TwoFactorController extends Controller
{
    public function __construct(protected PlatformTwoFactor $twoFactor) {}

    public function show(Request $request): View
    {
        $admin = $this->admin($request);

        return view('platform.account.security', [
            'admin' => $admin,
            'qrCode' => $admin->hasPendingTwoFactor() ? $this->twoFactor->qrCodeSvg($admin, (string) $admin->two_factor_secret) : null,
            'setupKey' => $admin->hasPendingTwoFactor() ? $admin->two_factor_secret : null,
        ]);
    }

    /**
     * Start enrollment with a new secret (replacing an unconfirmed one).
     */
    public function enable(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $this->confirmPassword($request, $admin);

        if ($admin->hasTwoFactorEnabled()) {
            return redirect()->route('platform.account.security');
        }

        $admin->forceFill([
            'two_factor_secret' => $this->twoFactor->generateSecret(),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestep' => null,
        ])->save();

        return redirect()->route('platform.account.security');
    }

    public function confirm(Request $request, EndPlatformAdminAccess $endAccess): Response|RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        $admin = $this->admin($request);

        if (! $admin->hasPendingTwoFactor()) {
            return redirect()->route('platform.account.security');
        }

        $this->throttle($admin);

        if (! $this->twoFactor->verify($admin, $request->string('code')->toString())) {
            RateLimiter::hit($this->throttleKey($admin), 300);

            throw ValidationException::withMessages(['code' => __('The provided two-factor authentication code was invalid.')]);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();
        $admin->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();

        PlatformAuditLog::record($admin, null, PlatformAuditAction::TwoFactorEnabled);

        // A browser signed in with the password alone (before 2FA) must sign in again.
        $endAccess->handle($admin, $request->session()->getId());

        return $this->recoveryCodesResponse($codes);
    }

    public function regenerateRecoveryCodes(Request $request): Response|RedirectResponse
    {
        $admin = $this->admin($request);
        $this->confirmPassword($request, $admin);
        $this->confirmCode($request, $admin);

        $codes = $this->twoFactor->generateRecoveryCodes();
        $admin->forceFill(['two_factor_recovery_codes' => $codes])->save();

        PlatformAuditLog::record($admin, null, PlatformAuditAction::TwoFactorRecoveryCodesRegenerated);

        return $this->recoveryCodesResponse($codes);
    }

    public function disable(Request $request, EndPlatformAdminAccess $endAccess): RedirectResponse
    {
        $admin = $this->admin($request);

        if (config('platform.require_two_factor') && $admin->hasTwoFactorEnabled()) {
            return redirect()->route('platform.account.security')->with('status', __('Two-factor authentication is required for Platform Admins and cannot be disabled. If the authenticator is lost, an operator can reset it from the command line.'));
        }

        $this->confirmPassword($request, $admin);

        if ($admin->hasTwoFactorEnabled()) {
            $this->confirmCode($request, $admin);
        }

        $wasEnabled = $admin->hasTwoFactorEnabled();

        $admin->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestep' => null,
        ])->save();

        if ($wasEnabled) {
            PlatformAuditLog::record($admin, null, PlatformAuditAction::TwoFactorDisabled);

            // Authentication got weaker: other browsers and open support sessions end.
            $endAccess->handle($admin, $request->session()->getId(), endSupportSessions: true);
        }

        return redirect()->route('platform.account.security')->with('status', __('Two-factor authentication is disabled.'));
    }

    /**
     * @param  list<string>  $codes
     */
    protected function recoveryCodesResponse(array $codes): Response
    {
        return response()
            ->view('platform.account.recovery-codes', ['codes' => $codes])
            ->header('Cache-Control', 'no-store, private');
    }

    protected function admin(Request $request): PlatformAdmin
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        return $admin;
    }

    /**
     * @throws ValidationException
     */
    protected function confirmPassword(Request $request, PlatformAdmin $admin): void
    {
        $request->validate(['password' => ['required', 'string']]);
        $this->throttle($admin);

        if (! Hash::check($request->string('password')->toString(), $admin->password)) {
            RateLimiter::hit($this->throttleKey($admin), 300);

            throw ValidationException::withMessages(['password' => __('The password is incorrect.')]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function confirmCode(Request $request, PlatformAdmin $admin): void
    {
        $request->validate(['code' => ['nullable', 'string', 'max:20'], 'recovery_code' => ['nullable', 'string', 'max:100']]);

        if (! $this->twoFactor->verifyCodeOrRecoveryCode($admin, $request->input('code'), $request->input('recovery_code'))) {
            RateLimiter::hit($this->throttleKey($admin), 300);

            throw ValidationException::withMessages(['code' => __('The provided two-factor authentication code was invalid.')]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function throttle(PlatformAdmin $admin): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey($admin), 5)) {
            throw ValidationException::withMessages([
                'password' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($this->throttleKey($admin))]),
            ]);
        }
    }

    protected function throttleKey(PlatformAdmin $admin): string
    {
        return 'platform-security|'.$admin->id;
    }
}
