<?php

use App\Actions\Roles\SaveRole;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permissions added for FCL Operations and the company audit log.
 *
 * @return list<string>
 */
function fclFoundationPermissions(): array
{
    return [
        TenantPermission::AuditShow->value,
        TenantPermission::FclJobsShow->value,
        TenantPermission::FclJobsShowAllBranches->value,
        TenantPermission::FclJobsCreate->value,
        TenantPermission::FclJobsEdit->value,
        TenantPermission::FclJobsCancel->value,
        TenantPermission::FclAciEdit->value,
        TenantPermission::FclPartiesShow->value,
        TenantPermission::FclPartiesCreate->value,
        TenantPermission::FclPartiesEdit->value,
    ];
}

/**
 * Put the current tenant back in the state of a company provisioned before these
 * permissions existed.
 */
function removeFclFoundationPermissions(): void
{
    Permission::query()->whereIn('name', fclFoundationPermissions())->get()->each->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

test('a new company gets the FCL permissions for its Super Admin role only', function () {
    actingInTenant();

    expect(Permission::query()->whereIn('name', fclFoundationPermissions())->count())->toBe(count(fclFoundationPermissions()))
        ->and(Role::findByName(TenantRole::SuperAdmin->value, 'web')->permissions)->toBeEmpty()
        ->and(Role::findByName(TenantRole::Employee->value, 'web')->permissions)->toBeEmpty();

    $admin = superAdminUser();
    $employee = User::factory()->create()->assignRole(TenantRole::Employee->value);

    foreach (fclFoundationPermissions() as $permission) {
        expect($admin->can($permission))->toBeTrue()
            ->and($employee->can($permission))->toBeFalse();
    }
});

test('synchronization adds them to existing companies without touching custom roles or the Employee role', function () {
    $acme = actingInTenant('acme');
    $documentation = Role::create(['name' => 'Documentation', 'guard_name' => 'web'])->givePermissionTo(TenantPermission::EmployeesShow->value);
    Role::findByName(TenantRole::Employee->value, 'web')->givePermissionTo(TenantPermission::BillingShow->value);
    removeFclFoundationPermissions();
    tenancy()->end();

    $globex = provisionTenant('globex')->tenant;
    $globex->run(fn () => removeFclFoundationPermissions());

    expect(Artisan::call('tenants:sync-authorization'))->toBe(0);

    foreach ([$acme, $globex] as $tenant) {
        $tenant->run(function (): void {
            expect(superAdminUser()->can(TenantPermission::FclPartiesCreate->value))->toBeTrue()
                ->and(Role::findByName(TenantRole::SuperAdmin->value, 'web')->permissions)->toBeEmpty();
        });
    }

    $acme->run(function () use ($documentation): void {
        expect($documentation->fresh()->permissions->pluck('name')->all())->toBe([TenantPermission::EmployeesShow->value])
            ->and(Role::findByName(TenantRole::Employee->value, 'web')->permissions->pluck('name')->all())->toBe([TenantPermission::BillingShow->value]);
    });

    // Running it again changes nothing.
    expect(Artisan::call('tenants:sync-authorization'))->toBe(0);
    $acme->run(fn () => expect(Permission::query()->whereIn('name', fclFoundationPermissions())->count())->toBe(count(fclFoundationPermissions())));
});

test('a Super Admin can grant FCL permissions through a custom role', function () {
    actingInTenant();
    $admin = superAdminUser();
    $clerk = User::factory()->create();

    $role = app(SaveRole::class)->handle($admin, null, 'Documentation', [TenantPermission::FclJobsShow->value, TenantPermission::FclJobsCreate->value]);
    $clerk->assignRole($role);

    expect($clerk->can(TenantPermission::FclJobsCreate->value))->toBeTrue()
        ->and($clerk->can(TenantPermission::FclJobsEdit->value))->toBeFalse()
        ->and($clerk->can(TenantPermission::AuditShow->value))->toBeFalse();
});

test('a role manager cannot grant FCL permissions they do not hold', function () {
    actingInTenant();
    $roleManager = userWithPermissions([TenantPermission::RolesShow, TenantPermission::RolesCreate, TenantPermission::FclJobsShow]);

    expect(fn () => app(SaveRole::class)->handle($roleManager, null, 'Escalated', [TenantPermission::FclJobsShow->value, TenantPermission::FclJobsCreate->value]))
        ->toThrow(AuthorizationException::class);

    $role = app(SaveRole::class)->handle($roleManager, null, 'Readers', [TenantPermission::FclJobsShow->value]);

    expect($role->permissions->pluck('name')->all())->toBe([TenantPermission::FclJobsShow->value])
        ->and(Role::query()->where('name', 'Escalated')->exists())->toBeFalse();
});

test('the role editor offers the new permissions', function () {
    $tenant = provisionTenant('acme')->tenant;
    loginToTenant($tenant, $tenant->run(fn () => superAdminUser()));

    $this->get($tenant->url('roles'))->assertOk()
        ->assertSee(TenantPermission::FclJobsCreate->value)
        ->assertSee(TenantPermission::AuditShow->value);
});

test('permissions of one company do not leak into another', function () {
    $acme = provisionTenant('acme')->tenant;
    $globex = provisionTenant('globex')->tenant;

    $acme->run(fn () => Role::create(['name' => 'Documentation', 'guard_name' => 'web'])->givePermissionTo(TenantPermission::FclJobsCreate->value));

    $globex->run(function (): void {
        expect(Role::query()->where('name', 'Documentation')->exists())->toBeFalse()
            ->and(User::factory()->create()->assignRole(TenantRole::Employee->value)->can(TenantPermission::FclJobsCreate->value))->toBeFalse();
    });
});
