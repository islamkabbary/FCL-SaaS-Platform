<?php

use App\Actions\Tenancy\ProvisionTenant;
use App\Enums\TenantStatus;
use App\Models\Branch;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Events\DatabaseCreated;

function tenantDatabaseExists(string $database): bool
{
    return DB::connection('tenant_template')
        ->selectOne('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?', [$database]) !== null;
}

/**
 * @return array<string, string>
 */
function validTenantPayload(array $overrides = []): array
{
    return [
        'company_name' => 'Acme Freight',
        'subdomain' => 'acme',
        'admin_name' => 'Alice Admin',
        'admin_email' => 'alice@acme.test',
        ...$overrides,
    ];
}

test('a platform admin can provision a tenant with its own database, default branch and tenant admin', function () {
    $response = $this->actingAs(PlatformAdmin::factory()->create(), 'platform')
        ->post(route('platform.tenants.store'), validTenantPayload());

    $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $result = $response->viewData('result');
    $response->assertSee($result->temporaryPassword);

    $tenant = Tenant::query()->sole();
    expect($tenant->name)->toBe('Acme Freight')
        ->and($tenant->status)->toBe(TenantStatus::Ready)
        ->and($tenant->provisioned_at)->not->toBeNull()
        ->and($tenant->domains->pluck('domain')->all())->toBe(['acme'])
        ->and($tenant->database()->getName())->toBe('fcl_test_tenant_'.$tenant->id)
        ->and(tenantDatabaseExists($tenant->database()->getName()))->toBeTrue();

    $tenant->run(function () use ($result) {
        expect(Schema::hasTable('users'))->toBeTrue()
            ->and(Schema::hasTable('branches'))->toBeTrue();

        $branch = Branch::query()->sole();
        expect($branch->code)->toBe(Branch::DefaultCode)
            ->and($branch->is_default)->toBeTrue();

        $admin = User::query()->sole();
        expect($admin->email)->toBe('alice@acme.test')
            ->and($admin->is_tenant_admin)->toBeTrue()
            ->and($admin->must_change_password)->toBeTrue()
            ->and($admin->primary_branch_id)->toBe($branch->id)
            ->and(Hash::check($result->temporaryPassword, $admin->password))->toBeTrue();
    });
});

test('central tables never receive tenant data', function () {
    provisionTenant('acme');

    expect(Schema::connection('mysql')->hasTable('users'))->toBeFalse()
        ->and(Schema::connection('mysql')->hasTable('branches'))->toBeFalse();
});

test('the temporary password is neither logged nor stored in the session', function () {
    $loggedMessages = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$loggedMessages) {
        $loggedMessages[] = $event->message.json_encode($event->context);
    });

    $response = $this->actingAs(PlatformAdmin::factory()->create(), 'platform')
        ->post(route('platform.tenants.store'), validTenantPayload());

    $temporaryPassword = $response->viewData('result')->temporaryPassword;

    expect(json_encode(session()->all()))->not->toContain($temporaryPassword)
        ->and(implode("\n", $loggedMessages))->not->toContain($temporaryPassword)
        ->and(print_r($response->viewData('result'), true))->not->toContain($temporaryPassword);
});

test('guests can not provision tenants', function () {
    $this->post(route('platform.tenants.store'), validTenantPayload())
        ->assertRedirect(route('platform.login'));

    expect(Tenant::query()->count())->toBe(0);
});

test('tenant provisioning requires all fields', function () {
    $this->actingAs(PlatformAdmin::factory()->create(), 'platform')
        ->post(route('platform.tenants.store'), [])
        ->assertSessionHasErrors(['company_name', 'subdomain', 'admin_name', 'admin_email']);

    expect(Tenant::query()->count())->toBe(0);
});

test('tenant provisioning rejects invalid subdomains', function (string $subdomain, string $message) {
    $this->actingAs(PlatformAdmin::factory()->create(), 'platform')
        ->post(route('platform.tenants.store'), validTenantPayload(['subdomain' => $subdomain]))
        ->assertSessionHasErrors(['subdomain' => $message]);

    expect(Tenant::query()->count())->toBe(0);
})->with([
    'reserved' => ['www', 'This subdomain is reserved.'],
    'underscore' => ['acme_co', 'The subdomain may only contain lowercase letters, numbers and single hyphens.'],
    'dot' => ['acme.evil', 'The subdomain may only contain lowercase letters, numbers and single hyphens.'],
    'too short' => ['ab', 'The subdomain field must be at least 3 characters.'],
]);

test('tenant provisioning rejects a subdomain that is already taken', function () {
    provisionTenant('acme');

    $this->actingAs(PlatformAdmin::factory()->create(), 'platform')
        ->post(route('platform.tenants.store'), validTenantPayload(['subdomain' => 'ACME']))
        ->assertSessionHasErrors(['subdomain' => 'The subdomain has already been taken.']);

    expect(Tenant::query()->count())->toBe(1);
});

test('a failed provisioning marks the tenant as failed and can be resumed', function () {
    $shouldFail = true;
    Event::listen(DatabaseCreated::class, function () use (&$shouldFail) {
        if ($shouldFail) {
            throw new RuntimeException('Simulated provisioning failure');
        }
    });

    $response = $this->actingAs(PlatformAdmin::factory()->create(), 'platform')
        ->post(route('platform.tenants.store'), validTenantPayload());

    $tenant = Tenant::query()->sole();

    // The details page shows the failure summary and the recovery command.
    $response->assertRedirect(route('platform.tenants.show', $tenant))->assertSessionHas('error');
    expect($tenant->status)->toBe(TenantStatus::Failed)
        ->and($tenant->provisioning_error)->toContain('Simulated provisioning failure')
        ->and(tenancy()->initialized)->toBeFalse();

    $this->get($tenant->url('login'))->assertNotFound();

    $shouldFail = false;

    $this->artisan('tenants:provision', ['tenant' => 'acme'])
        ->expectsOutputToContain('Temporary password (shown once)')
        ->assertSuccessful();

    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Ready)
        ->and($tenant->provisioning_error)->toBeNull();

    $tenant->run(fn () => expect(User::query()->where('email', 'alice@acme.test')->exists())->toBeTrue());

    $this->get($tenant->url('login'))->assertOk();
});

test('a tenant another run is provisioning cannot be provisioned at the same time', function () {
    $tenant = Tenant::create([
        'name' => 'Busy Co',
        'status' => TenantStatus::Provisioning,
        'initial_admin_name' => 'Bob',
        'initial_admin_email' => 'bob@busy.test',
    ]);
    $tenant->domains()->create(['domain' => 'busy']);

    $this->artisan('tenants:provision', ['tenant' => 'busy'])
        ->expectsOutputToContain('already being provisioned by another run')
        ->assertFailed();

    expect($tenant->refresh()->status)->toBe(TenantStatus::Provisioning)
        ->and(tenantDatabaseExists($tenant->database()->getName()))->toBeFalse();

    $this->actingAs(PlatformAdmin::factory()->create(), 'platform')
        ->get(route('platform.tenants.show', $tenant))
        ->assertDontSeeHtml('data-test="provisioning-stale"');
});

test('a provisioning run that stopped can be resumed once it is stale', function () {
    $tenant = Tenant::create([
        'name' => 'Stuck Co',
        'status' => TenantStatus::Provisioning,
        'initial_admin_name' => 'Sam',
        'initial_admin_email' => 'sam@stuck.test',
    ]);
    $tenant->domains()->create(['domain' => 'stuck']);

    // The run died: no progress for longer than the stale threshold.
    $this->travel(ProvisionTenant::StaleAfterMinutes + 1)->minutes();

    $this->actingAs(PlatformAdmin::factory()->create(), 'platform')
        ->get(route('platform.tenants.show', $tenant))
        ->assertSeeHtml('data-test="provisioning-stale"')
        ->assertSee('php artisan tenants:provision stuck');

    $this->artisan('tenants:provision', ['tenant' => 'stuck'])
        ->expectsOutputToContain('Temporary password (shown once)')
        ->assertSuccessful();

    expect($tenant->refresh()->status)->toBe(TenantStatus::Ready);
});

test('an already provisioned tenant is not provisioned again', function () {
    provisionTenant('acme');

    $this->artisan('tenants:provision', ['tenant' => 'acme'])
        ->expectsOutputToContain('already provisioned')
        ->assertSuccessful();
});
