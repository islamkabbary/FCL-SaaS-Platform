<?php

use App\Actions\Employees\SyncEmployeeRoles;
use App\Actions\Employees\UpdateEmployee;
use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\SaveRole;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->admin = superAdminUser();
});

test('a super admin can create, edit and delete a custom role', function () {
    $component = Livewire::actingAs($this->admin)->test('pages::roles.index')
        ->call('create')
        ->set('name', 'HR Viewer')
        ->set('selectedPermissions', [TenantPermission::EmployeesShow->value])
        ->call('save')
        ->assertHasNoErrors();

    $role = Role::findByName('HR Viewer', 'web');
    expect($role->permissions->pluck('name')->all())->toBe([TenantPermission::EmployeesShow->value]);

    $component->call('edit', $role->id)
        ->set('name', 'HR Team')
        ->set('selectedPermissions', [TenantPermission::EmployeesShow->value, TenantPermission::EmployeesCreate->value])
        ->call('save')
        ->assertHasNoErrors();

    $role->refresh();
    expect($role->name)->toBe('HR Team')
        ->and($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe([TenantPermission::EmployeesCreate->value, TenantPermission::EmployeesShow->value]);

    $component->call('delete', $role->id);

    expect(Role::query()->where('name', 'HR Team')->exists())->toBeFalse();
});

test('role names are validated and reserved names are refused', function () {
    $component = Livewire::actingAs($this->admin)->test('pages::roles.index');

    $component->set('name', TenantRole::Employee->value)->call('save')->assertHasErrors(['name' => 'unique']);
    $component->set('name', '')->call('save')->assertHasErrors(['name' => 'required']);
    $component->set('name', 'Bad')->set('selectedPermissions', ['platform.everything'])->call('save')
        ->assertHasErrors(['selectedPermissions.0' => 'in']);
});

test('a role grants its permissions to the employees holding it', function () {
    $employee = User::factory()->create()->assignRole(TenantRole::Employee->value);

    $this->actingAs($employee)->get(route('employees.index'))->assertForbidden();

    $role = app(SaveRole::class)->handle($this->admin, null, 'Viewer', [TenantPermission::EmployeesShow->value]);
    app(SyncEmployeeRoles::class)->handle($this->admin, $employee, [$role->id]);

    $this->actingAs($employee->refresh())->get(route('employees.index'))->assertOk();
});

test('the built-in super admin role cannot be modified or deleted', function () {
    $superAdminRole = Role::findByName(TenantRole::SuperAdmin->value, 'web');

    expect($this->admin->can('update', $superAdminRole))->toBeFalse()
        ->and($this->admin->can('delete', $superAdminRole))->toBeFalse()
        ->and(fn () => app(SaveRole::class)->handle($this->admin, $superAdminRole, TenantRole::SuperAdmin->value, []))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteRole::class)->handle($this->admin, $superAdminRole))
        ->toThrow(AuthorizationException::class);

    expect($superAdminRole->refresh()->permissions)->toHaveCount(0);
});

test('the built-in employee role keeps its name and cannot be deleted, but its permissions can change', function () {
    $employeeRole = Role::findByName(TenantRole::Employee->value, 'web');

    expect(fn () => app(SaveRole::class)->handle($this->admin, $employeeRole, 'Staff', []))
        ->toThrow(AuthorizationException::class, 'Built-in roles cannot be renamed.')
        ->and(fn () => app(DeleteRole::class)->handle($this->admin, $employeeRole))
        ->toThrow(AuthorizationException::class);

    app(SaveRole::class)->handle($this->admin, $employeeRole, TenantRole::Employee->value, [TenantPermission::EmployeesShow->value]);

    expect($employeeRole->refresh()->permissions->pluck('name')->all())->toBe([TenantPermission::EmployeesShow->value]);
});

test('employees without role permissions cannot view or manage roles', function () {
    $employee = User::factory()->create()->assignRole(TenantRole::Employee->value);

    $this->actingAs($employee)->get(route('roles.index'))->assertForbidden();

    expect(fn () => app(SaveRole::class)->handle($employee, null, 'Mine', []))
        ->toThrow(AuthorizationException::class);
});

test('a role manager cannot grant permissions they do not hold', function () {
    $roleManager = userWithPermissions([TenantPermission::RolesShow, TenantPermission::RolesCreate, TenantPermission::RolesEdit, TenantPermission::RolesDelete]);
    $powerful = Role::create(['name' => 'Powerful', 'guard_name' => 'web'])
        ->syncPermissions([TenantPermission::EmployeesCreate->value]);

    expect(fn () => app(SaveRole::class)->handle($roleManager, null, 'Escalate', [TenantPermission::EmployeesCreate->value]))
        ->toThrow(AuthorizationException::class)
        ->and($roleManager->can('update', $powerful))->toBeFalse()
        ->and($roleManager->can('delete', $powerful))->toBeFalse();

    // Their own role cannot be widened either.
    $ownRole = $roleManager->roles->first();
    expect(fn () => app(SaveRole::class)->handle($roleManager, $ownRole, $ownRole->name, [
        TenantPermission::RolesShow->value, TenantPermission::RolesCreate->value, TenantPermission::RolesEdit->value, TenantPermission::RolesDelete->value, TenantPermission::RolesAssign->value,
    ]))->toThrow(AuthorizationException::class);

    expect($roleManager->refresh()->hasPermissionTo(TenantPermission::RolesAssign->value))->toBeFalse();
});

test('employees cannot grant themselves or others higher privileges through role assignment', function () {
    $assigner = userWithPermissions([TenantPermission::RolesAssign, TenantPermission::EmployeesShow, TenantPermission::EmployeesEdit]);
    $colleague = User::factory()->create()->assignRole(TenantRole::Employee->value);
    $superAdminRole = Role::findByName(TenantRole::SuperAdmin->value, 'web');
    $managerRole = Role::create(['name' => 'Manager', 'guard_name' => 'web'])
        ->syncPermissions([TenantPermission::RolesEdit->value]);

    // Own roles.
    expect(fn () => app(SyncEmployeeRoles::class)->handle($assigner, $assigner, [$superAdminRole->id]))
        ->toThrow(AuthorizationException::class);

    // The Super Admin role, or a role with permissions the assigner lacks.
    expect(fn () => app(SyncEmployeeRoles::class)->handle($assigner, $colleague, [$superAdminRole->id]))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(SyncEmployeeRoles::class)->handle($assigner, $colleague, [$managerRole->id]))
        ->toThrow(AuthorizationException::class);

    // Through the Livewire page as well.
    Livewire::actingAs($assigner)->test('pages::employees.index')
        ->call('edit', $colleague->id)
        ->set('selectedRoles', [(string) $superAdminRole->id])
        ->call('save');

    expect($assigner->refresh()->isSuperAdmin())->toBeFalse()
        ->and($colleague->refresh()->getRoleNames()->all())->toBe([TenantRole::Employee->value]);
});

test('lower-privileged users cannot edit, demote or take over a super admin', function () {
    $hrManager = userWithPermissions([TenantPermission::EmployeesShow, TenantPermission::EmployeesEdit, TenantPermission::RolesAssign]);

    expect($hrManager->can('update', $this->admin))->toBeFalse()
        ->and(fn () => app(UpdateEmployee::class)->handle($hrManager, $this->admin, ['name' => 'x', 'email' => 'attacker@evil.test']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(SyncEmployeeRoles::class)->handle($hrManager, $this->admin, []))
        ->toThrow(AuthorizationException::class);

    expect($this->admin->refresh()->email)->not->toBe('attacker@evil.test')
        ->and($this->admin->isSuperAdmin())->toBeTrue();
});

test('a super admin can grant the super admin role to another employee', function () {
    $employee = User::factory()->create()->assignRole(TenantRole::Employee->value);
    $superAdminRole = Role::findByName(TenantRole::SuperAdmin->value, 'web');

    app(SyncEmployeeRoles::class)->handle($this->admin, $employee, [$superAdminRole->id]);

    expect($employee->refresh()->isSuperAdmin())->toBeTrue()
        ->and($employee->hasPermissionTo(TenantPermission::RolesEdit->value))->toBeTrue();
});
