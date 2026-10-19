<?php

namespace App\Actions\Employees;

use App\Enums\TenantRole;
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
 * existing logins end immediately.
 */
class SetEmployeeActivation
{
    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, User $employee, bool $active): void
    {
        if ($active) {
            Gate::forUser($actor)->authorize('activate', $employee);

            $employee->forceFill(['is_active' => true, 'deactivated_at' => null])->save();

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
