<?php

use App\Actions\Employees\SyncEmployeeRoles;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\User;
use App\Tenancy\PermissionCacheBootstrapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('role and permission tables exist only in tenant databases', function () {
    $tenant = provisionTenant('acme')->tenant;

    foreach (['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'] as $table) {
        expect(Schema::connection('mysql')->hasTable($table))->toBeFalse()
            ->and($tenant->run(fn () => Schema::hasTable($table)))->toBeTrue();
    }
});

test('provisioning seeds the permission catalog, the built-in roles and the tenant admin role', function () {
    $result = provisionTenant('acme');

    $result->tenant->run(function () use ($result) {
        $admin = User::query()->where('email', $result->adminEmail)->sole();

        expect(Permission::query()->pluck('name')->sort()->values()->all())->toBe(collect(TenantPermission::values())->sort()->values()->all())
            ->and(Role::query()->pluck('name')->sort()->values()->all())->toBe([TenantRole::Employee->value, TenantRole::TenantAdmin->value])
            ->and($admin->getRoleNames()->all())->toBe([TenantRole::TenantAdmin->value])
            ->and($admin->getAllPermissions()->pluck('name')->sort()->values()->all())->toBe(collect(TenantPermission::values())->sort()->values()->all())
            ->and(Role::findByName(TenantRole::Employee->value, 'web')->permissions)->toHaveCount(0);
    });
});

test('an existing tenant without roles is backfilled idempotently by tenants:sync-authorization', function () {
    $result = provisionTenant('acme');

    // Simulate a tenant provisioned before Milestone 2: no roles or permissions yet.
    $result->tenant->run(function () {
        foreach (['model_has_roles', 'model_has_permissions', 'role_has_permissions', 'roles', 'permissions'] as $table) {
            DB::table($table)->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    });

    $this->artisan('tenants:sync-authorization')->assertSuccessful();

    $result->tenant->run(function () use ($result) {
        expect(User::query()->where('email', $result->adminEmail)->sole()->isTenantAdmin())->toBeTrue();

        // A Tenant Admin customizes the Employee role; a re-run must keep it.
        Role::findByName(TenantRole::Employee->value, 'web')->syncPermissions([TenantPermission::EmployeesView->value]);
    });

    $this->artisan('tenants:sync-authorization', ['--tenant' => ['acme']])->assertSuccessful();

    $result->tenant->run(function () {
        expect(Role::query()->count())->toBe(2)
            ->and(Permission::query()->count())->toBe(count(TenantPermission::cases()))
            ->and(DB::table('model_has_roles')->count())->toBe(1)
            ->and(Role::findByName(TenantRole::Employee->value, 'web')->permissions->pluck('name')->all())
            ->toBe([TenantPermission::EmployeesView->value]);
    });
});

test('tenants:sync-authorization does not promote a demoted founder again', function () {
    $tenant = provisionTenant('acme')->tenant;

    $tenant->run(function () {
        $founder = tenantAdminUser();
        $second = User::factory()->create()->assignRole(TenantRole::TenantAdmin->value);

        app(SyncEmployeeRoles::class)->handle($second, $founder, [Role::findByName(TenantRole::Employee->value, 'web')->id]);
        expect($founder->refresh()->isTenantAdmin())->toBeFalse();
    });

    $this->artisan('tenants:sync-authorization')->assertSuccessful();

    $tenant->run(function () {
        expect(User::query()->where('is_tenant_admin', true)->sole()->isTenantAdmin())->toBeFalse()
            ->and(User::query()->role(TenantRole::TenantAdmin->value)->count())->toBe(1);
    });
});

test('a tenant admin only sees and manages their own company\'s employees', function () {
    $acme = provisionTenant('acme')->tenant;
    $globex = provisionTenant('globex')->tenant;
    $globexUserId = $globex->run(function () {
        User::factory()->count(3)->create();

        return User::factory()->create(['name' => 'Globex Only'])->id;
    });

    tenancy()->initialize($acme);
    URL::forceRootUrl(rtrim($acme->url(), '/'));
    $admin = tenantAdminUser();

    $this->actingAs($admin)->get(route('employees.index'))
        ->assertOk()
        ->assertSee('admin@acme.test')
        ->assertDontSee('admin@globex.test')
        ->assertDontSee('Globex Only');

    expect(User::query()->find($globexUserId))->toBeNull();

    Livewire::actingAs($admin)->test('pages::employees.index')
        ->call('edit', $globexUserId)
        ->assertNotFound();

    Livewire::actingAs($admin)->test('pages::employees.index')
        ->call('deactivate', $globexUserId)
        ->assertNotFound();

    $globex->run(fn () => expect(User::query()->findOrFail($globexUserId)->is_active)->toBeTrue());
});

test('roles of another tenant cannot be assigned', function () {
    $acme = provisionTenant('acme')->tenant;
    $globex = provisionTenant('globex')->tenant;
    $globexRoleId = $globex->run(function () {
        Role::create(['name' => 'Filler 1', 'guard_name' => 'web']);
        Role::create(['name' => 'Filler 2', 'guard_name' => 'web']);

        return Role::create(['name' => 'Globex Secret', 'guard_name' => 'web'])->id;
    });

    tenancy()->initialize($acme);
    $admin = tenantAdminUser();
    $employee = User::factory()->create()->assignRole(TenantRole::Employee->value);

    expect(Role::query()->find($globexRoleId))->toBeNull();

    Livewire::actingAs($admin)->test('pages::employees.index')
        ->call('edit', $employee->id)
        ->set('selectedRoles', [(string) $globexRoleId])
        ->call('save')
        ->assertHasErrors(['selectedRoles.0' => 'exists']);

    expect($employee->refresh()->getRoleNames()->all())->toBe([TenantRole::Employee->value]);
});

test('a tenant admin cannot reach another tenant\'s administration pages', function () {
    $acme = provisionTenant('acme')->tenant;
    $globex = provisionTenant('globex')->tenant;
    $admin = $acme->run(fn () => tenantAdminUser());

    // A tenant session replayed on another tenant is rejected (ScopeSessions).
    $this->actingAs($admin)->get($acme->url('employees'))->assertOk();
    auth()->forgetGuards();

    $this->get($globex->url('employees'))->assertForbidden();
});

test('permission caches and in-memory permission state never leak between tenants', function () {
    $acme = provisionTenant('acme')->tenant;
    $globex = provisionTenant('globex')->tenant;

    // Role ids collide across tenant databases: acme's "Viewer" and globex's "Plain" share an id,
    // but only acme's role holds employees.view. A permission collection left in memory from
    // acme would make globex's "Plain" holder pass the check (roles are matched by id).
    [$acmeRoleId, $acmeUserId] = $acme->run(function () {
        $role = Role::create(['name' => 'Viewer', 'guard_name' => 'web'])->syncPermissions([TenantPermission::EmployeesView->value]);

        return [$role->id, User::factory()->create()->assignRole($role)->id];
    });
    [$globexRoleId, $globexUserId] = $globex->run(function () {
        $role = Role::create(['name' => 'Plain', 'guard_name' => 'web']);

        return [$role->id, User::factory()->create()->assignRole($role)->id];
    });
    expect($globexRoleId)->toBe($acmeRoleId);

    $canView = fn (int $userId): bool => User::query()->findOrFail($userId)->can(TenantPermission::EmployeesView->value);
    $registrar = app(PermissionRegistrar::class);

    // Switch back and forth in one process; each tenant sees only its own data.
    tenancy()->initialize($acme);
    expect($canView($acmeUserId))->toBeTrue()
        ->and($registrar->cacheKey)->toBe(PermissionCacheBootstrapper::CacheKeyPrefix.$acme->id);

    tenancy()->initialize($globex);
    expect($canView($globexUserId))->toBeFalse()
        ->and($registrar->cacheKey)->toBe(PermissionCacheBootstrapper::CacheKeyPrefix.$globex->id);

    tenancy()->initialize($acme);
    expect($canView($acmeUserId))->toBeTrue();

    tenancy()->end();
    expect($registrar->cacheKey)->toBe(config('permission.cache.key'));
});

test('permission data is not cached in the central database', function () {
    $tenant = provisionTenant('acme')->tenant;

    $tenant->run(fn () => tenantAdminUser()->can(TenantPermission::EmployeesView->value));

    expect(DB::connection('mysql')->table('cache')->where('key', 'like', '%spatie.permission%')->count())->toBe(0);
});
