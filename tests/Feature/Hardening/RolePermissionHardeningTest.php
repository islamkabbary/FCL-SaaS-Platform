<?php

use App\Actions\Employees\CreateEmployee;
use App\Actions\Roles\SaveRole;
use App\Actions\Tenancy\SyncTenantAuthorization;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Milestone 5.2: a permission retired from the catalog never locks Tenant Admins out,
// and no custom role can take a built-in role's name.

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->admin = tenantAdminUser();
});

/**
 * A custom role holding a catalog permission and one no longer in the catalog (as after
 * an enum case was renamed or removed and the sync ran).
 */
function roleWithRetiredPermission(string $retired = 'fcl.legacy.retired'): Role
{
    Permission::findOrCreate($retired, 'web');
    $role = Role::create(['name' => 'Customs', 'guard_name' => 'web']);
    $role->givePermissionTo([TenantPermission::EmployeesView->value, $retired]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $role;
}

test('a tenant admin can still manage a role and its holders when the role holds a retired permission', function () {
    $role = roleWithRetiredPermission();
    $holder = User::factory()->create()->assignRole($role);

    expect($this->admin->can('update', $role))->toBeTrue()
        ->and($this->admin->can('delete', $role))->toBeTrue()
        ->and($this->admin->can('update', $holder))->toBeTrue()
        ->and($this->admin->can('deactivate', $holder))->toBeTrue();

    $saved = app(SaveRole::class)->handle($this->admin, $role, 'Customs team', [TenantPermission::EmployeesView->value]);
    expect($saved->name)->toBe('Customs team');
});

test('employees can still be created when the employee role holds a retired permission', function () {
    Permission::findOrCreate('fcl.legacy.retired', 'web');
    Role::findByName(TenantRole::Employee->value, 'web')->givePermissionTo('fcl.legacy.retired');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $created = app(CreateEmployee::class)->handle($this->admin, ['name' => 'New Clerk', 'email' => 'new@acme.test']);

    expect($created->employee->exists)->toBeTrue();
});

test('the sync deletes permissions retired from the catalog and keeps every catalog grant', function () {
    $role = roleWithRetiredPermission();

    app(SyncTenantAuthorization::class)->handle();

    expect(Permission::query()->where('name', 'fcl.legacy.retired')->exists())->toBeFalse()
        ->and($role->fresh()->permissions->pluck('name')->all())->toBe([TenantPermission::EmployeesView->value])
        ->and(Permission::query()->pluck('name')->sort()->values()->all())->toBe(collect(TenantPermission::values())->sort()->values()->all());
});

test('a renamed permission keeps its role and direct grants', function () {
    Permission::findOrCreate('fcl.aci.fix', 'web');
    $role = Role::create(['name' => 'Documentation', 'guard_name' => 'web'])->givePermissionTo('fcl.aci.fix');
    $direct = User::factory()->create()->givePermissionTo('fcl.aci.fix');

    $sync = new class extends SyncTenantAuthorization
    {
        protected function renamedPermissions(): array
        {
            return ['fcl.aci.fix' => TenantPermission::FclAciCorrect->value];
        }
    };
    $sync->handle();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Permission::query()->where('name', 'fcl.aci.fix')->exists())->toBeFalse()
        ->and($role->fresh()->hasPermissionTo(TenantPermission::FclAciCorrect->value))->toBeTrue()
        ->and($direct->fresh()->hasDirectPermission(TenantPermission::FclAciCorrect->value))->toBeTrue();
});

test('no custom role can be created with or renamed to a built-in name or a look-alike', function (string $name) {
    $custom = app(SaveRole::class)->handle($this->admin, null, 'Support desk', []);

    expect(fn () => app(SaveRole::class)->handle($this->admin, null, $name, []))->toThrow(AuthorizationException::class, 'This role name is reserved.')
        ->and(fn () => app(SaveRole::class)->handle($this->admin, $custom, $name, []))->toThrow(AuthorizationException::class, 'This role name is reserved.');

    expect($custom->fresh()->name)->toBe('Support desk');
})->with(['Tenant Admin', 'tenant admin', ' Tenant  Admin ', "Tenant\tAdmin", 'EMPLOYEE', 'Employee ']);

test('role names are stored with their whitespace normalised, also through the roles page', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::roles.index')
        ->call('create')
        ->set('name', '  QA   Team ')
        ->call('save')
        ->assertHasNoErrors();

    expect(Role::query()->where('name', 'QA Team')->exists())->toBeTrue();

    // The look-alike of an existing custom role is refused as a duplicate.
    Livewire::actingAs($this->admin)
        ->test('pages::roles.index')
        ->call('create')
        ->set('name', ' QA Team')
        ->call('save')
        ->assertHasErrors('name');
});
