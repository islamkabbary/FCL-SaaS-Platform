<?php

use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\SetEmployeeActivation;
use App\Actions\Employees\UpdateEmployee;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;

// Milestone 5.2: privileged changes are authorized against the state after the lock, and
// employees find the default branch even if its code changes.

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->admin = tenantAdminUser();
    $this->secondAdmin = User::factory()->create(['email' => 'second@acme.test'])->assignRole(TenantRole::TenantAdmin->value);
    $this->clerk = userWithPermissions([TenantPermission::EmployeesView], ['email' => 'clerk@acme.test']);
});

/**
 * Demote the second Tenant Admin as soon as the action's transaction begins, as a request
 * that committed just before this one's lock would have: this action loaded the second
 * admin's privileges before that.
 */
function demoteSecondAdminWhenTheTransactionBegins(): void
{
    $demoted = false;

    Event::listen(TransactionBeginning::class, function () use (&$demoted): void {
        if (! $demoted) {
            $demoted = true;
            User::query()->where('email', 'second@acme.test')->sole()->syncRoles([TenantRole::Employee->value]);
        }
    });
}

test('a tenant admin demoted while waiting for the lock cannot deactivate anyone', function () {
    $this->secondAdmin->getAllPermissions(); // privileges loaded before the request waits
    demoteSecondAdminWhenTheTransactionBegins();

    expect(fn () => app(SetEmployeeActivation::class)->handle($this->secondAdmin, $this->clerk, false))
        ->toThrow(AuthorizationException::class);

    expect($this->clerk->fresh()->is_active)->toBeTrue();
});

test('a tenant admin demoted while waiting for the lock cannot change roles', function () {
    $this->secondAdmin->getAllPermissions();
    demoteSecondAdminWhenTheTransactionBegins();
    $tenantAdminRoleId = Role::findByName(TenantRole::TenantAdmin->value, 'web')->id;

    expect(fn () => app(UpdateEmployee::class)->handle($this->secondAdmin, $this->clerk, ['name' => 'Clerk', 'email' => 'clerk@acme.test'], [$tenantAdminRoleId]))
        ->toThrow(AuthorizationException::class);

    expect($this->clerk->fresh()->isTenantAdmin())->toBeFalse();
});

test('an employee is placed in the default branch even when its code was changed', function () {
    Branch::query()->where('is_default', true)->sole()->update(['code' => 'HQ']);

    $created = app(CreateEmployee::class)->handle($this->admin, ['name' => 'New Clerk', 'email' => 'new@acme.test']);

    expect($created->employee->primary_branch_id)->toBe(Branch::query()->where('code', 'HQ')->sole()->id);
});
