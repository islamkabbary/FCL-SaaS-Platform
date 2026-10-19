<?php

use App\Actions\Tenancy\ProvisioningResult;
use App\Actions\Tenancy\ProvisionTenant;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Create and fully provision a tenant through the real provisioning action.
 *
 * @param  array<string, string>  $attributes
 */
function provisionTenant(string $subdomain = 'acme', array $attributes = []): ProvisioningResult
{
    return app(ProvisionTenant::class)->create([
        'company_name' => Str::headline($subdomain).' Freight',
        'subdomain' => $subdomain,
        'admin_name' => 'Admin of '.$subdomain,
        'admin_email' => "admin@{$subdomain}.test",
        ...$attributes,
    ]);
}

/**
 * Provision a tenant and run the rest of the test inside its context: models use
 * its database and route() generates URLs on its subdomain.
 */
function actingInTenant(string $subdomain = 'acme'): Tenant
{
    $tenant = provisionTenant($subdomain)->tenant;

    tenancy()->initialize($tenant);
    URL::forceRootUrl(rtrim($tenant->url(), '/'));

    return $tenant;
}

/**
 * The provisioned Tenant Admin of the current tenant, past the forced password change.
 */
function tenantAdminUser(): User
{
    $admin = User::role(TenantRole::TenantAdmin->value)->firstOrFail();
    $admin->forceFill(['must_change_password' => false])->save();

    return $admin;
}

/**
 * Create a user in the current tenant holding exactly the given permissions (through a custom role).
 *
 * @param  list<TenantPermission>  $permissions
 * @param  array<string, mixed>  $attributes
 */
function userWithPermissions(array $permissions, array $attributes = []): User
{
    $role = Role::create(['name' => 'Custom '.Str::random(8), 'guard_name' => 'web']);
    $role->syncPermissions(array_map(fn (TenantPermission $permission): string => $permission->value, $permissions));

    return User::factory()->create($attributes)->assignRole($role);
}
