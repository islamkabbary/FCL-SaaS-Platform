<?php

use App\Actions\Billing\RequestSeatChange;
use App\Actions\Employees\SyncEmployeeRoles;
use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\SaveRole;
use App\Enums\RoleAuditAction;
use App\Enums\SeatRequestType;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\Fcl\Party;
use App\Models\TenantAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->owner = superAdminUser();
});

test('a super admin passes every catalog permission without holding any permission row', function () {
    expect($this->owner->getAllPermissions())->toHaveCount(0);

    foreach (TenantPermission::cases() as $permission) {
        expect($this->owner->can($permission->value))->toBeTrue()
            ->and($this->owner->hasPermissionTo($permission))->toBeTrue();
    }

    // Only catalog names: anything else is still decided by its own gate or policy.
    expect($this->owner->can('platform.everything'))->toBeFalse()
        ->and($this->owner->checkPermissionTo('platform.everything'))->toBeFalse();
});

test('the exemption never bypasses the rules that bind a super admin too', function () {
    $superAdminRole = Role::findByName(TenantRole::SuperAdmin->value, 'web');

    expect($this->owner->can('deactivate', $this->owner))->toBeFalse()
        ->and($this->owner->can('assignRoles', $this->owner))->toBeFalse()
        ->and($this->owner->can('update', $superAdminRole))->toBeFalse()
        ->and($this->owner->can('delete', $superAdminRole))->toBeFalse()
        ->and($this->owner->can('delete', Role::findByName(TenantRole::Employee->value, 'web')))->toBeFalse();

    $second = User::factory()->create()->assignRole(TenantRole::SuperAdmin->value);

    expect($second->can('deactivate', $this->owner))->toBeTrue();
});

test('only a super admin can give the super admin role, and the form hides it from everyone else', function () {
    $assigner = userWithPermissions(TenantPermission::cases());
    $employee = User::factory()->create()->assignRole(TenantRole::Employee->value);
    $superAdminRole = Role::findByName(TenantRole::SuperAdmin->value, 'web');

    expect(fn () => app(SyncEmployeeRoles::class)->handle($assigner, $employee, [$superAdminRole->id]))
        ->toThrow(AuthorizationException::class);

    expect(Livewire::actingAs($assigner)->test('pages::employees.index')->instance()->assignableRoles->pluck('name')->all())
        ->toContain(TenantRole::Employee->value)
        ->not->toContain(TenantRole::SuperAdmin->value);

    $limited = userWithPermissions([TenantPermission::EmployeesShow, TenantPermission::EmployeesCreate, TenantPermission::RolesAssign]);
    $broad = app(SaveRole::class)->handle($this->owner, null, 'Accountant', [TenantPermission::BillingShow->value]);

    expect(Livewire::actingAs($limited)->test('pages::employees.index')->instance()->assignableRoles->pluck('name')->all())
        ->not->toContain($broad->name, TenantRole::SuperAdmin->value);

    app(SyncEmployeeRoles::class)->handle($this->owner, $employee, [$superAdminRole->id]);
    expect($employee->fresh()->isSuperAdmin())->toBeTrue();
});

test('a split permission grants only its own operation', function () {
    $roleCreator = userWithPermissions([TenantPermission::RolesShow, TenantPermission::RolesCreate]);
    $role = app(SaveRole::class)->handle($roleCreator, null, 'Clerks', []);

    expect(fn () => app(SaveRole::class)->handle($roleCreator, $role, 'Clerks team', []))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteRole::class)->handle($roleCreator, $role))->toThrow(AuthorizationException::class);

    $partyCreator = userWithPermissions([TenantPermission::FclPartiesShow, TenantPermission::FclPartiesCreate]);

    expect($partyCreator->can('create', Party::class))->toBeTrue()
        ->and($partyCreator->can('update', createParty()))->toBeFalse();
});

test('requesting seats and withdrawing a request are separate permissions', function () {
    $requester = userWithPermissions([TenantPermission::BillingShow, TenantPermission::BillingSeatRequestsCreate]);

    $request = app(RequestSeatChange::class)->handle($requester, SeatRequestType::Increase, 2);

    expect(fn () => app(RequestSeatChange::class)->cancel($requester, $request->id))
        ->toThrow(AuthorizationException::class, 'You are not allowed to withdraw seat requests.');

    $withdrawer = userWithPermissions([TenantPermission::BillingSeatRequestsDelete]);
    app(RequestSeatChange::class)->cancel($withdrawer, $request->id);

    expect($request->fresh()->status->value)->toBe('cancelled')
        ->and(fn () => app(RequestSeatChange::class)->handle($withdrawer, SeatRequestType::Increase, 1))->toThrow(AuthorizationException::class);
});

test('creating, changing and deleting a role are recorded in the company audit log', function () {
    $role = app(SaveRole::class)->handle($this->owner, null, 'Accountant', [TenantPermission::BillingShow->value]);
    app(SaveRole::class)->handle($this->owner, $role, 'Accountant', [TenantPermission::BillingShow->value, TenantPermission::AuditShow->value]);
    app(SaveRole::class)->handle($this->owner, $role->fresh(), 'Accountant', [TenantPermission::BillingShow->value, TenantPermission::AuditShow->value]);
    app(DeleteRole::class)->handle($this->owner, $role->fresh());

    $records = TenantAuditLog::query()->where('action', 'like', 'role.%')->orderBy('id')->get();

    expect($records->pluck('action')->all())->toBe([RoleAuditAction::RoleCreated->value, RoleAuditAction::RoleUpdated->value, RoleAuditAction::RoleDeleted->value])
        ->and($records[0]->old_values)->toBeNull()
        ->and($records[0]->new_values)->toBe(['name' => 'Accountant', 'permissions' => ['billing.show']])
        ->and($records[1]->old_values)->toBe(['name' => 'Accountant', 'permissions' => ['billing.show']])
        ->and($records[1]->new_values)->toBe(['name' => 'Accountant', 'permissions' => ['audit.show', 'billing.show']])
        ->and($records[2]->old_values)->toBe(['name' => 'Accountant', 'permissions' => ['audit.show', 'billing.show']])
        ->and($records->every(fn (TenantAuditLog $record): bool => $record->actor_user_id === $this->owner->id))->toBeTrue();
});

test('the role editor shows a matrix of sections and actions, in both languages', function (string $locale, string $show) {
    app()->setLocale($locale);

    Livewire::actingAs($this->owner)->test('pages::roles.index')
        ->call('create')
        ->assertSeeHtml('data-test="permission-matrix"')
        ->assertSee($show)
        ->assertSee(TenantPermission::resourceLabel('fcl.parties'))
        ->assertSee(TenantPermission::EmployeesActivate->label())
        ->assertSeeHtml('data-test="super-admin-all-permissions"');
})->with([
    'English' => ['en', 'Show'],
    'Arabic' => ['ar', 'عرض'],
]);
