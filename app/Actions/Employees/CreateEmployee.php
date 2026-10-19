<?php

namespace App\Actions\Employees;

use App\Billing\SeatGuard;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Exceptions\NoSeatAvailable;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Create an employee in the current tenant with a one-time temporary password.
 *
 * Employees are assigned to the Default Branch (additional branches are out of
 * scope) and must change the temporary password at first login. An employee is created
 * active, so a purchased seat must be available (SeatGuard).
 */
class CreateEmployee
{
    public function __construct(
        protected SyncEmployeeRoles $syncEmployeeRoles,
        protected SeatGuard $seatGuard,
    ) {}

    /**
     * @param  array{name: string, email: string}  $attributes
     * @param  list<int>  $roleIds  Roles to assign; the Employee role is used when empty.
     *
     * @throws AuthorizationException
     * @throws NoSeatAvailable
     */
    public function handle(User $actor, array $attributes, array $roleIds = []): CreatedEmployee
    {
        Gate::forUser($actor)->authorize('create', User::class);

        if ($roleIds === []) {
            $roleIds = [Role::findByName(TenantRole::Employee->value, 'web')->getKey()];
        } elseif (! $actor->hasPermissionTo(TenantPermission::RolesAssign->value)) {
            throw new AuthorizationException(__('You are not allowed to assign roles.'));
        }

        return $this->seatGuard->claim(fn (): CreatedEmployee => DB::transaction(function () use ($actor, $attributes, $roleIds): CreatedEmployee {
            $temporaryPassword = Str::password(20);

            $employee = new User;
            $employee->forceFill([
                'name' => $attributes['name'],
                'email' => Str::lower($attributes['email']),
                'password' => $temporaryPassword,
                'primary_branch_id' => Branch::query()->where('code', Branch::DefaultCode)->sole()->id,
                'must_change_password' => true,
                'is_active' => true,
                'email_verified_at' => now(),
            ])->save();

            $this->syncEmployeeRoles->handle($actor, $employee, $roleIds, isNewEmployee: true);

            return new CreatedEmployee($employee, $temporaryPassword);
        }));
    }
}
