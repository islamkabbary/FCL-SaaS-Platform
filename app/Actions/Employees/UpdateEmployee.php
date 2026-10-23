<?php

namespace App\Actions\Employees;

use App\Actions\Auth\EndOtherSessions;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\EmailAddressChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Update an employee's details and, when given, their roles.
 */
class UpdateEmployee
{
    public function __construct(
        protected SyncEmployeeRoles $syncEmployeeRoles,
        protected EndOtherSessions $endOtherSessions,
    ) {}

    /**
     * A changed email is a new login identity: the password reset link goes to it. So the
     * email is no longer verified, the employee is signed out everywhere (the actor's own
     * current session excepted), and the previous address is told about the change.
     *
     * Returns whether the email changed.
     *
     * @param  array{name: string, email: string}  $attributes
     * @param  list<int>|null  $roleIds  Null leaves the roles untouched.
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, User $employee, array $attributes, ?array $roleIds = null): bool
    {
        Gate::forUser($actor)->authorize('update', $employee);

        $oldEmail = $employee->email;
        $newEmail = Str::lower($attributes['email']);
        $emailChanged = $newEmail !== $oldEmail;

        DB::transaction(function () use ($actor, $employee, $attributes, $roleIds, $newEmail, $emailChanged): void {
            if ($roleIds !== null) {
                // Lock the active Tenant Admins so two admins cannot demote each other at once
                // (a locking read: see User::activeTenantAdminCount()).
                User::activeTenantAdminCount();
            }

            $employee->forceFill([
                'name' => $attributes['name'],
                'email' => $newEmail,
                ...($emailChanged ? ['email_verified_at' => null] : []),
            ])->save();

            if ($emailChanged) {
                $keepSessionId = $actor->is($employee) && request()->hasSession() ? request()->session()->getId() : null;

                $this->endOtherSessions->handle($employee, $keepSessionId);
            }

            $currentRoleIds = $employee->roles()->pluck('id')->sort()->values()->all();

            if ($roleIds !== null && collect($roleIds)->unique()->sort()->values()->all() !== $currentRoleIds) {
                $this->syncEmployeeRoles->handle($actor, $employee, $roleIds);
            }
        });

        if ($emailChanged) {
            $tenant = tenant();

            Notification::route('mail', $oldEmail)
                ->notify(new EmailAddressChanged($oldEmail, $newEmail, $actor->name, $tenant instanceof Tenant ? $tenant->name : null));
        }

        return $emailChanged;
    }
}
