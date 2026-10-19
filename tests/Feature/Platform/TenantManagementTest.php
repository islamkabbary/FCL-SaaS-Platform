<?php

use App\Actions\Platform\SuspendTenant;
use App\Enums\PlatformAuditAction;
use App\Enums\TenantStatus;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;

beforeEach(function () {
    $this->admin = PlatformAdmin::factory()->create();
});

test('guests cannot reach any tenant management page', function (string $route, string $method) {
    $tenant = centralTenant('acme');

    $this->{$method}(route($route, $tenant))->assertRedirect(route('platform.login'));

    expect($tenant->refresh()->isSuspended())->toBeFalse()
        ->and($tenant->name)->toBe('Acme Freight')
        ->and(PlatformAuditLog::count())->toBe(0);
})->with([
    'details' => ['platform.tenants.show', 'get'],
    'history' => ['platform.tenants.history', 'get'],
    'edit' => ['platform.tenants.edit', 'get'],
    'update' => ['platform.tenants.update', 'put'],
    'suspend confirmation' => ['platform.tenants.suspend', 'get'],
    'suspend' => ['platform.tenants.suspend.store', 'post'],
    'reactivate' => ['platform.tenants.reactivate.store', 'post'],
]);

test('tenant management pages are not reachable from a tenant subdomain', function () {
    $tenant = provisionTenant('acme')->tenant;

    $this->actingAs($this->admin, 'platform')
        ->get($tenant->url("admin/tenants/{$tenant->id}"))
        ->assertNotFound();
});

test('platform admins can list tenants with both statuses', function () {
    centralTenant('acme');
    centralTenant('globex', TenantStatus::Failed);

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.tenants.index'))
        ->assertOk()
        ->assertSeeInOrder(['Globex Freight', 'globex', 'Failed', 'Active'])
        ->assertSee('Acme Freight');
});

test('the tenant list can be searched and filtered', function (array $query, array $expected) {
    centralTenant('acme', attributes: ['name' => 'Acme Shipping']);
    centralTenant('globex', TenantStatus::Failed, ['name' => 'Globex Logistics']);
    centralTenant('initech', TenantStatus::Pending, ['name' => 'Initech Cargo']);
    $umbrella = centralTenant('umbrella', attributes: ['name' => 'Umbrella Lines']);
    app(SuspendTenant::class)->handle($this->admin, $umbrella, 'Requested by the customer');

    $response = $this->actingAs($this->admin, 'platform')
        ->get(route('platform.tenants.index', $query))
        ->assertOk();

    $listed = $response->viewData('tenants')->pluck('name')->sort()->values()->all();

    expect($listed)->toBe($expected);
})->with([
    'search by company name' => [['search' => 'logis'], ['Globex Logistics']],
    'search by subdomain' => [['search' => 'umbre'], ['Umbrella Lines']],
    'wildcards are matched literally' => [['search' => '%'], []],
    'provisioning status' => [['status' => 'pending'], ['Initech Cargo']],
    'access status' => [['access' => 'suspended'], ['Umbrella Lines']],
    'combined filters' => [['status' => 'ready', 'access' => 'active'], ['Acme Shipping']],
    'unknown filter values are ignored' => [['status' => 'deleted', 'access' => 'banned'], ['Acme Shipping', 'Globex Logistics', 'Initech Cargo', 'Umbrella Lines']],
]);

test('the tenant list is paginated', function () {
    foreach (range(1, 16) as $number) {
        centralTenant("tenant-{$number}");
    }

    $response = $this->actingAs($this->admin, 'platform')->get(route('platform.tenants.index'))->assertOk();
    expect($response->viewData('tenants'))->toHaveCount(15);

    $response = $this->actingAs($this->admin, 'platform')->get(route('platform.tenants.index', ['page' => 2]))->assertOk();
    expect($response->viewData('tenants'))->toHaveCount(1);
});

test('tenant details show the central record without credentials', function () {
    $tenant = provisionTenant('acme')->tenant;

    $response = $this->actingAs($this->admin, 'platform')
        ->get(route('platform.tenants.show', $tenant))
        ->assertOk()
        ->assertSee('Acme Freight')
        ->assertSee($tenant->id)
        ->assertSee('fcl_test_tenant_'.$tenant->id)
        ->assertSee('http://acme.localhost')
        ->assertSee('admin@acme.test')
        ->assertSee($tenant->provisioned_at->toDayDateTimeString())
        ->assertSee('Suspend access')
        ->assertDontSee('tenancy_db');

    $databasePassword = (string) config('database.connections.mysql.password');

    if ($databasePassword !== '') {
        $response->assertDontSee($databasePassword);
    }
});

test('a failed tenant shows a sanitized failure summary and the recovery command', function () {
    $tenant = centralTenant('globex', TenantStatus::Failed, [
        'provisioning_error' => 'QueryException: SQLSTATE[HY000] [1045] Access denied for user (Connection: mysql, Host: 10.0.0.5, Port: 3306, Database: fcl, SQL: select password=hunter2)',
    ]);

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.tenants.show', $tenant))
        ->assertOk()
        ->assertSee('Provisioning failed')
        ->assertSee('Access denied for user')
        ->assertSee('php artisan tenants:provision globex')
        ->assertDontSee('10.0.0.5')
        ->assertDontSee('hunter2')
        ->assertDontSee('Suspend access');
});

test('credential-like values in a failure summary are redacted', function () {
    $tenant = new Tenant(['provisioning_error' => 'RuntimeException: login failed with password=hunter2 and token: abc123']);

    expect($tenant->provisioningFailureSummary())
        ->toBe('RuntimeException: login failed with password=[redacted] and token: [redacted]');
});

test('an unknown tenant returns not found', function () {
    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.tenants.show', 'does-not-exist'))
        ->assertNotFound();
});

test('platform admins can rename a tenant and the change is audited', function () {
    $tenant = centralTenant('acme');

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.tenants.edit', $tenant))
        ->assertOk();

    $this->actingAs($this->admin, 'platform')
        ->put(route('platform.tenants.update', $tenant), ['name' => 'Acme Global Freight'])
        ->assertRedirect(route('platform.tenants.show', $tenant))
        ->assertSessionHasNoErrors();

    expect($tenant->refresh()->name)->toBe('Acme Global Freight');

    $entry = PlatformAuditLog::sole();
    expect($entry->action)->toBe(PlatformAuditAction::TenantUpdated)
        ->and($entry->platform_admin_id)->toBe($this->admin->id)
        ->and($entry->actor_email)->toBe($this->admin->email)
        ->and($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->old_values)->toBe(['name' => 'Acme Freight'])
        ->and($entry->new_values)->toBe(['name' => 'Acme Global Freight']);
});

test('only the company name can be changed through the update form', function () {
    $tenant = centralTenant('acme');

    $this->actingAs($this->admin, 'platform')
        ->put(route('platform.tenants.update', $tenant), [
            'name' => 'Acme Freight',
            'id' => 'another-id',
            'status' => 'failed',
            'subdomain' => 'evil',
            'suspended_at' => now()->toDateTimeString(),
        ])
        ->assertRedirect(route('platform.tenants.show', $tenant));

    $tenant->refresh();

    expect($tenant->status)->toBe(TenantStatus::Ready)
        ->and($tenant->isSuspended())->toBeFalse()
        ->and($tenant->primaryDomain()->domain)->toBe('acme')
        ->and(Tenant::query()->whereKey('another-id')->exists())->toBeFalse()
        // Nothing changed, so nothing is audited.
        ->and(PlatformAuditLog::count())->toBe(0);
});

test('the company name is validated', function (mixed $name) {
    $tenant = centralTenant('acme');

    $this->actingAs($this->admin, 'platform')
        ->put(route('platform.tenants.update', $tenant), ['name' => $name])
        ->assertSessionHasErrors('name');

    expect($tenant->refresh()->name)->toBe('Acme Freight');
})->with([
    'empty' => [''],
    'too long' => [str_repeat('a', 256)],
    'not a string' => [['Acme']],
]);

test('creating a tenant through the platform is audited', function () {
    $this->actingAs($this->admin, 'platform')
        ->post(route('platform.tenants.store'), [
            'company_name' => 'Acme Freight',
            'subdomain' => 'acme',
            'admin_name' => 'Alice',
            'admin_email' => 'alice@acme.test',
        ])
        ->assertOk();

    $entry = PlatformAuditLog::sole();

    expect($entry->action)->toBe(PlatformAuditAction::TenantCreated)
        ->and($entry->platform_admin_id)->toBe($this->admin->id)
        ->and($entry->new_values)->toEqual(['name' => 'Acme Freight', 'subdomain' => 'acme', 'status' => 'ready'])
        // The one-time temporary password is never written to the audit trail.
        ->and(json_encode($entry->getAttributes()))->not->toContain('password');
});

test('the history page lists every audit entry of the tenant only', function () {
    $acme = centralTenant('acme');
    $globex = centralTenant('globex');

    app(SuspendTenant::class)->handle($this->admin, $acme, 'Acme reason');
    app(SuspendTenant::class)->handle($this->admin, $globex, 'Globex reason');

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.tenants.history', $acme))
        ->assertOk()
        ->assertSee('Acme reason')
        ->assertDontSee('Globex reason');
});

test('audit records are read-only', function () {
    $tenant = centralTenant('acme');
    app(SuspendTenant::class)->handle($this->admin, $tenant, 'Contract under review');
    $entry = PlatformAuditLog::sole();

    expect(fn () => $entry->update(['reason' => 'Rewritten']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and($entry->fresh()->reason)->toBe('Contract under review');
});
