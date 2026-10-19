<?php

namespace App\Actions\Employees;

use App\Enums\TenantRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Update an employee's details and, when given, their roles.
 */
class UpdateEmployee
{
    public function __construct(protected SyncEmployeeRoles $syncEmployeeRoles) {}

    /**
     * @param  array{name: string, email: string}  $attributes
     * @param  list<int>|null  $roleIds  Null leaves the roles untouched.
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, User $employee, array $attributes, ?array $roleIds = null): void
    {
        Gate::forUser($actor)->authorize('update', $employee);

        DB::transaction(function () use ($actor, $employee, $attributes, $roleIds): void {
            $employee->forceFill([
                'name' => $attributes['name'],
                'email' => Str::lower($attributes['email']),
            ])->save();

            $currentRoleIds = $employee->roles()->pluck('id')->sort()->values()->all();

            if ($roleIds !== null && collect($roleIds)->unique()->sort()->values()->all() !== $currentRoleIds) {
                // Lock the active Tenant Admins so two admins cannot demote each other at once.
                User::query()->active()->role(TenantRole::TenantAdmin->value)->lockForUpdate()->get();

                $this->syncEmployeeRoles->handle($actor, $employee, $roleIds);
            }
        });
    }
}
