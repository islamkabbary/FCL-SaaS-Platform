<?php

use App\Actions\Tenancy\SyncTenantAuthorization;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('every permission is a resource with one of the four basic actions or an approved special action', function () {
    foreach (TenantPermission::cases() as $permission) {
        expect($permission->value)->toBe($permission->resource().'.'.$permission->action())
            ->and([...TenantPermission::BasicActions, ...TenantPermission::SpecialActions])->toContain($permission->action());
    }

    expect(collect(TenantPermission::cases())->reject->isBasic()->map->value->sort()->values()->all())
        ->toBe(['employees.activate', 'fcl.jobs.cancel', 'fcl.jobs.show-all-branches', 'roles.assign']);
});

test('no permission is named manage, view or update', function () {
    expect(collect(TenantPermission::values())->filter(fn (string $name): bool => preg_match('/\.(manage|view|update)$/', $name) === 1)->all())->toBe([]);
});

test('every former permission maps to catalog names', function () {
    foreach (TenantPermission::renamed() as $old => $new) {
        expect(TenantPermission::tryFrom($old))->toBeNull()
            ->and($new)->not->toBeEmpty()
            ->and(array_diff($new, TenantPermission::values()))->toBe([]);
    }
});

describe('the sync of a tenant created before the split', function () {
    beforeEach(function () {
        $this->tenant = actingInTenant();
    });

    test('gives every holder of a split permission each of its new permissions', function () {
        foreach (['roles.manage', 'fcl.parties.manage', 'billing.manage', 'employees.view'] as $old) {
            Permission::findOrCreate($old, 'web');
        }
        $role = Role::create(['name' => 'Office manager', 'guard_name' => 'web'])
            ->givePermissionTo(['roles.manage', 'fcl.parties.manage', 'employees.view']);
        $direct = User::factory()->create()->givePermissionTo('billing.manage');

        app(SyncTenantAuthorization::class)->handle();
        app(SyncTenantAuthorization::class)->handle();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        expect($role->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe([
            'employees.show', 'fcl.parties.create', 'fcl.parties.edit', 'roles.create', 'roles.delete', 'roles.edit',
        ])
            ->and($direct->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all())
            ->toBe(['billing.seat-requests.create', 'billing.seat-requests.delete'])
            ->and(Permission::query()->whereIn('name', ['roles.manage', 'fcl.parties.manage', 'billing.manage', 'employees.view'])->exists())->toBeFalse();
    });

    test('renames the Tenant Admin role to Super Admin and keeps its holders', function () {
        $owner = superAdminUser();
        Role::findByName(TenantRole::SuperAdmin->value, 'web')->forceFill(['name' => TenantRole::LegacySuperAdminName])->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        app(SyncTenantAuthorization::class)->handle();

        expect(Role::query()->where('name', TenantRole::LegacySuperAdminName)->exists())->toBeFalse()
            ->and($owner->fresh()->isSuperAdmin())->toBeTrue()
            ->and(Role::findByName(TenantRole::SuperAdmin->value, 'web')->permissions)->toHaveCount(0);
    });
});
