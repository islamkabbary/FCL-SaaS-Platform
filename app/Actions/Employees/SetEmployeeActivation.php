<?php

namespace App\Actions\Employees;

use App\Billing\SeatGuard;
use App\Enums\TenantRole;
use App\Exceptions\NoSeatAvailable;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Activate or deactivate an employee.
 *
 * Deactivation is enforced on every request by EnsureUserIsActive; in addition the
 * employee's stored sessions are deleted and their "remember me" token rotated, so
 * existing logins end immediately. A deactivated employee no longer consumes a seat;
 * reactivation needs an available purchased seat (SeatGuard).
 */
class SetEmployeeActivation
{
    public function __construct(protected SeatGuard $seatGuard) {}

    /**
     * @throws AuthorizationException
     * @throws NoSeatAvailable
     */
    public function handle(User $actor, User $employee, bool $active): void
    {
        if ($active) {
            Gate::forUser($actor)->authorize('activate', $employee);

            if ($employee->is_active) {
                return;
            }

            $this->seatGuard->claim(function () use ($employee): void {
                // Re-read under the seat lock: a concurrent request may have activated them already.
                if ($employee->refresh()->is_active) {
                    return;
                }

                $employee->forceFill(['is_active' => true, 'deactivated_at' => null])->save();
            });

            return;
        }

        DB::transaction(function () use ($actor, $employee): void {
            // Lock the active Tenant Admins so two admins cannot deactivate each other at once.
            User::query()->active()->role(TenantRole::TenantAdmin->value)->lockForUpdate()->get();

            Gate::forUser($actor)->authorize('deactivate', $employee);

            $employee->forceFill([
                'is_active' => false,
                'deactivated_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();

            if (config('session.driver') === 'database') {
                DB::table(config('session.table'))->where('user_id', $employee->getKey())->delete();
            }
        });
    }
}
