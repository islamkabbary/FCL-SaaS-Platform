<?php

namespace App\Actions\Impersonation;

use App\Exceptions\ImpersonationRefused;
use App\Impersonation\ActiveImpersonation;
use App\Impersonation\Impersonator;
use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Lets a Tenant Admin view the application as one of their employees (tenant context).
 *
 * Requires the Tenant Admin's own password for this request (rate limited), a reason,
 * and the "impersonate" ability (EmployeeImpersonationRules). The employee's password
 * and sessions are never used or changed. Nested impersonation is refused.
 */
class StartEmployeeImpersonation
{
    public const MaxPasswordAttempts = 5;

    public function __construct(protected Impersonator $impersonator) {}

    /**
     * @throws AuthorizationException
     * @throws ImpersonationRefused
     * @throws ValidationException
     */
    public function handle(Request $request, User $admin, int $employeeId, string $reason, string $password): ImpersonationSession
    {
        if (ActiveImpersonation::current($request) !== null || $request->session()->has(Impersonator::SessionKey)) {
            throw ImpersonationRefused::because(__('End the current impersonation session first.'));
        }

        $this->confirmPassword($admin, $password);

        $employee = User::query()->whereKey($employeeId)->first();

        if ($employee === null) {
            throw ImpersonationRefused::because(__('The selected employee does not exist.'));
        }

        Gate::forUser($admin)->authorize('impersonate', $employee);

        $session = ImpersonationSession::query()->create([
            'impersonator_user_id' => $admin->id,
            'impersonator_name' => $admin->name,
            'impersonator_email' => $admin->email,
            'target_user_id' => $employee->id,
            'target_name' => $employee->name,
            'target_email' => $employee->email,
            'reason' => $reason,
            'started_at' => now(),
            'expires_at' => now()->addMinutes((int) config('impersonation.employee.lifetime_minutes')),
            'started_ip' => $request->ip(),
        ]);

        $this->impersonator->beginEmployee($request, $session, $admin, $employee);

        return $session;
    }

    /**
     * @throws ValidationException
     */
    protected function confirmPassword(User $admin, string $password): void
    {
        $key = 'impersonate|'.tenant()?->getTenantKey().'|'.$admin->id;

        if (RateLimiter::tooManyAttempts($key, self::MaxPasswordAttempts)) {
            throw ValidationException::withMessages([
                'password' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! Hash::check($password, $admin->password)) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['password' => __('The password is incorrect.')]);
        }

        RateLimiter::clear($key);
    }
}
