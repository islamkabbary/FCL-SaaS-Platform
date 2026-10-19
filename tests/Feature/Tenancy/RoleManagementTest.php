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
    $this->admin = tenantAdminUser();
});

test('a tenant admin can create, edit and delete a custom role', function () {
    $component = Livewire::actingAs($this->admin)->test('pages::roles.index')
        ->call('create')
        ->set('name', 'HR Viewer')
        ->set('selectedPermissions', [TenantPermission::EmployeesView->value])
        ->call('save')
        ->assertHasNoErrors();

    $role = Role::findByName('HR Viewer', 'web');
    expect($role->permissions->pluck('name')->all())->toBe([TenantPermission::EmployeesView->value]);

    $component->call('edit', $role->id)
        ->set('name', 'HR Team')
        ->set('selectedPermissions', [TenantPermission::EmployeesView->value, TenantPermission::EmployeesCreate->value])
        ->call('save')
        ->assertHasNoErrors();

    $role->refresh();
    expect($role->name)->toBe('HR Team')
        ->and($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe([TenantPermission::EmployeesCreate->value, TenantPermission::EmployeesView->value]);

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

    $role = app(SaveRole::class)->handle($this->admin, null, 'Viewer', [TenantPermission::EmployeesView->value]);
    app(SyncEmployeeRoles::class)->handle($this->admin, $employee, [$role->id]);

    $this->actingAs($employee->refresh())->get(route('employees.index'))->assertOk();
});

test('the built-in tenant admin role cannot be modified or deleted', function () {
    $tenantAdminRole = Role::findByName(TenantRole::TenantAdmin->value, 'web');

    expect($this->admin->can('update', $tenantAdminRole))->toBeFalse()
        ->and($this->admin->can('delete', $tenantAdminRole))->toBeFalse()
        ->and(fn () => app(SaveRole::class)->handle($this->admin, $tenantAdminRole, TenantRole::TenantAdmin->value, []))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteRole::class)->handle($this->admin, $tenantAdminRole))
        ->toThrow(AuthorizationException::class);

    expect($tenantAdminRole->refresh()->permissions)->toHaveCount(count(TenantPermission::cases()));
});

test('the built-in employee role keeps its name and cannot be deleted, but its permissions can change', function () {
    $employeeRole = Role::findByName(TenantRole::Employee->value, 'web');

    expect(fn () => app(SaveRole::class)->handle($this->admin, $employeeRole, 'Staff', []))
        ->toThrow(AuthorizationException::class, 'Built-in roles cannot be renamed.')
        ->and(fn () => app(DeleteRole::class)->handle($this->admin, $employeeRole))
        ->toThrow(AuthorizationException::class);

    app(SaveRole::class)->handle($this->admin, $employeeRole, TenantRole::Employee->value, [TenantPermission::EmployeesView->value]);

    expect($employeeRole->refresh()->permissions->pluck('name')->all())->toBe([TenantPermission::EmployeesView->value]);
});

test('employees without role permissions cannot view or manage roles', function () {
    $employee = User::factory()->create()->assignRole(TenantRole::Employee->value);

    $this->actingAs($employee)->get(route('roles.index'))->assertForbidden();

    expect(fn () => app(SaveRole::class)->handle($employee, null, 'Mine', []))
        ->toThrow(AuthorizationException::class);
});

test('a role manager cannot grant permissions they do not hold', function () {
    $roleManager = userWithPermissions([TenantPermission::RolesView, TenantPermission::RolesManage]);
    $powerful = Role::create(['name' => 'Powerful', 'guard_name' => 'web'])
        ->syncPermissions([TenantPermission::EmployeesCreate->value]);

    expect(fn () => app(SaveRole::class)->handle($roleManager, null, 'Escalate', [TenantPermission::EmployeesCreate->value]))
        ->toThrow(AuthorizationException::class)
        ->and($roleManager->can('update', $powerful))->toBeFalse()
        ->and($roleManager->can('delete', $powerful))->toBeFalse();

    // Their own role cannot be widened either.
    $ownRole = $roleManager->roles->first();
    expect(fn () => app(SaveRole::class)->handle($roleManager, $ownRole, $ownRole->name, [
        TenantPermission::RolesView->value, TenantPermission::RolesManage->value, TenantPermission::RolesAssign->value,
    ]))->toThrow(AuthorizationException::class);

    expect($roleManager->refresh()->hasPermissionTo(TenantPermission::RolesAssign->value))->toBeFalse();
});

test('employees cannot grant themselves or others higher privileges through role assignment', function () {
    $assigner = userWithPermissions([TenantPermission::RolesAssign, TenantPermission::EmployeesView, TenantPermission::EmployeesUpdate]);
    $colleague = User::factory()->create()->assignRole(TenantRole::Employee->value);
    $tenantAdminRole = Role::findByName(TenantRole::TenantAdmin->value, 'web');
    $managerRole = Role::create(['name' => 'Manager', 'guard_name' => 'web'])
        ->syncPermissions([TenantPermission::RolesManage->value]);

    // Own roles.
    expect(fn () => app(SyncEmployeeRoles::class)->handle($assigner, $assigner, [$tenantAdminRole->id]))
        ->toThrow(AuthorizationException::class);

    // The Tenant Admin role, or a role with permissions the assigner lacks.
    expect(fn () => app(SyncEmployeeRoles::class)->handle($assigner, $colleague, [$tenantAdminRole->id]))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(SyncEmployeeRoles::class)->handle($assigner, $colleague, [$managerRole->id]))
        ->toThrow(AuthorizationException::class);

    // Through the Livewire page as well.
    Livewire::actingAs($assigner)->test('pages::employees.index')
        ->call('edit', $colleague->id)
        ->set('selectedRoles', [(string) $tenantAdminRole->id])
        ->call('save');

    expect($assigner->refresh()->isTenantAdmin())->toBeFalse()
        ->and($colleague->refresh()->getRoleNames()->all())->toBe([TenantRole::Employee->value]);
});

test('lower-privileged users cannot edit, demote or take over a tenant admin', function () {
    $hrManager = userWithPermissions([TenantPermission::EmployeesView, TenantPermission::EmployeesUpdate, TenantPermission::RolesAssign]);

    expect($hrManager->can('update', $this->admin))->toBeFalse()
        ->and(fn () => app(UpdateEmployee::class)->handle($hrManager, $this->admin, ['name' => 'x', 'email' => 'attacker@evil.test']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(SyncEmployeeRoles::class)->handle($hrManager, $this->admin, []))
        ->toThrow(AuthorizationException::class);

    expect($this->admin->refresh()->email)->not->toBe('attacker@evil.test')
        ->and($this->admin->isTenantAdmin())->toBeTrue();
});

test('a tenant admin can grant the tenant admin role to another employee', function () {
    $employee = User::factory()->create()->assignRole(TenantRole::Employee->value);
    $tenantAdminRole = Role::findByName(TenantRole::TenantAdmin->value, 'web');

    app(SyncEmployeeRoles::class)->handle($this->admin, $employee, [$tenantAdminRole->id]);

    expect($employee->refresh()->isTenantAdmin())->toBeTrue()
        ->and($employee->hasPermissionTo(TenantPermission::RolesManage->value))->toBeTrue();
});
