<?php

use App\Actions\Platform\ReactivateTenant;
use App\Actions\Platform\SuspendTenant;
use App\Enums\PlatformAuditAction;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Exceptions\InvalidTenantTransition;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    $this->admin = PlatformAdmin::factory()->create();
});

/**
 * Provision a tenant with an employee who can sign in with "password".
 */
function tenantWithEmployee(string $subdomain): Tenant
{
    $tenant = provisionTenant($subdomain)->tenant;

    $tenant->run(fn () => User::factory()
        ->create(['email' => "employee@{$subdomain}.test"])
        ->assignRole(TenantRole::Employee->value));

    return $tenant;
}

/**
 * The absolute URL of Livewire's update endpoint on the tenant's subdomain.
 */
function livewireUpdateUrl(Tenant $tenant): string
{
    return $tenant->url(route('livewire.update', absolute: false));
}

test('platform admins can suspend a ready tenant with a reason', function () {
    $tenant = centralTenant('acme');

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.tenants.suspend', $tenant))
        ->assertOk()
        ->assertSee('Every user of this company loses access immediately.');

    $this->actingAs($this->admin, 'platform')
        ->post(route('platform.tenants.suspend.store', $tenant), ['reason' => 'Unpaid invoices since March'])
        ->assertRedirect(route('platform.tenants.show', $tenant))
        ->assertSessionHas('status');

    $tenant->refresh();

    expect($tenant->isSuspended())->toBeTrue()
        ->and($tenant->suspension_reason)->toBe('Unpaid invoices since March')
        ->and($tenant->status)->toBe(TenantStatus::Ready);

    $entry = PlatformAuditLog::sole();
    expect($entry->action)->toBe(PlatformAuditAction::TenantSuspended)
        ->and($entry->platform_admin_id)->toBe($this->admin->id)
        ->and($entry->actor_email)->toBe($this->admin->email)
        ->and($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->old_values)->toBe(['access_status' => 'active'])
        ->and($entry->new_values)->toBe(['access_status' => 'suspended'])
        ->and($entry->reason)->toBe('Unpaid invoices since March');
});

test('suspension requires a valid reason', function (mixed $reason) {
    $tenant = centralTenant('acme');

    $this->actingAs($this->admin, 'platform')
        ->post(route('platform.tenants.suspend.store', $tenant), ['reason' => $reason])
        ->assertSessionHasErrors('reason');

    expect($tenant->refresh()->isSuspended())->toBeFalse()
        ->and(PlatformAuditLog::count())->toBe(0);
})->with([
    'missing' => [null],
    'blank' => ['   '],
    'too short' => ['abc'],
    'too long' => [str_repeat('a', 1001)],
]);

test('tenants that are not ready cannot be suspended', function (TenantStatus $status) {
    $tenant = centralTenant('acme', $status);

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.tenants.suspend', $tenant))
        ->assertRedirect(route('platform.tenants.show', $tenant));

    $this->actingAs($this->admin, 'platform')
        ->post(route('platform.tenants.suspend.store', $tenant), ['reason' => 'Should not apply'])
        ->assertRedirect(route('platform.tenants.show', $tenant))
        ->assertSessionHas('error', "Only a ready tenant can be suspended. This tenant is {$status->value}.");

    $tenant->refresh();

    expect($tenant->isSuspended())->toBeFalse()
        ->and($tenant->status)->toBe($status)
        ->and(PlatformAuditLog::count())->toBe(0);
})->with([TenantStatus::Pending, TenantStatus::Provisioning, TenantStatus::Failed]);

test('a suspended tenant cannot be suspended again', function () {
    $tenant = centralTenant('acme');
    app(SuspendTenant::class)->handle($this->admin, $tenant, 'First suspension');

    $this->actingAs($this->admin, 'platform')
        ->post(route('platform.tenants.suspend.store', $tenant), ['reason' => 'Second suspension'])
        ->assertSessionHas('error', 'This tenant is already suspended.');

    expect($tenant->refresh()->suspension_reason)->toBe('First suspension')
        ->and(PlatformAuditLog::count())->toBe(1);
});

test('an active tenant cannot be reactivated', function () {
    $tenant = centralTenant('acme');

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.tenants.reactivate', $tenant))
        ->assertRedirect(route('platform.tenants.show', $tenant));

    $this->actingAs($this->admin, 'platform')
        ->post(route('platform.tenants.reactivate.store', $tenant))
        ->assertSessionHas('error', 'This tenant is not suspended.');

    expect(PlatformAuditLog::count())->toBe(0);
});

test('the actions reject invalid transitions on their own', function () {
    $pending = centralTenant('pending', TenantStatus::Pending);
    $active = centralTenant('active');

    expect(fn () => app(SuspendTenant::class)->handle($this->admin, $pending, 'Not ready'))->toThrow(InvalidTenantTransition::class)
        ->and(fn () => app(ReactivateTenant::class)->handle($this->admin, $active))->toThrow(InvalidTenantTransition::class);
});

test('the actions re-read the tenant instead of trusting a stale instance', function () {
    $tenant = centralTenant('acme');
    $staleCopy = Tenant::find($tenant->id);

    app(SuspendTenant::class)->handle($this->admin, $tenant, 'First suspension');

    // The stale copy still looks active, but the central record is suspended.
    expect($staleCopy->isSuspended())->toBeFalse()
        ->and(fn () => app(SuspendTenant::class)->handle($this->admin, $staleCopy, 'Second suspension'))
        ->toThrow(InvalidTenantTransition::class);
});

test('a suspended tenant\'s central record stays visible to platform admins', function () {
    $tenant = centralTenant('acme');
    app(SuspendTenant::class)->handle($this->admin, $tenant, 'Contract under review');

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.tenants.show', $tenant))
        ->assertOk()
        ->assertSee('Contract under review')
        ->assertSee('Reactivate access')
        ->assertDontSee('Suspend access');

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.tenants.reactivate', $tenant))
        ->assertOk();
});

test('suspended tenants cannot reach any tenant route', function (string $path) {
    $tenant = tenantWithEmployee('acme');
    app(SuspendTenant::class)->handle($this->admin, $tenant, 'Contract under review');

    $this->get($tenant->url($path))
        ->assertForbidden()
        ->assertSee('Access to this company account has been suspended.')
        ->assertDontSee('Contract under review');
})->with(['login', 'dashboard', 'employees', 'settings/profile', 'forgot-password']);

test('users of a suspended tenant cannot sign in', function () {
    $tenant = tenantWithEmployee('acme');
    app(SuspendTenant::class)->handle($this->admin, $tenant, 'Contract under review');

    $this->post($tenant->url('login'), ['email' => 'employee@acme.test', 'password' => 'password'])
        ->assertForbidden();

    $this->assertGuest('web');
});

test('an existing session cannot bypass suspension, and works again after reactivation', function () {
    $tenant = tenantWithEmployee('acme');

    $this->post($tenant->url('login'), ['email' => 'employee@acme.test', 'password' => 'password'])
        ->assertSessionHasNoErrors();
    $this->get($tenant->url('dashboard'))->assertOk();

    app(SuspendTenant::class)->handle($this->admin, $tenant, 'Contract under review');
    auth()->forgetGuards();

    // Tenancy is still initialized with the instance loaded before the suspension (as
    // in a long-lived worker), so this also proves the access check re-reads the tenant.
    $this->get($tenant->url('dashboard'))->assertForbidden();

    app(ReactivateTenant::class)->handle($this->admin, $tenant);
    auth()->forgetGuards();

    $this->get($tenant->url('dashboard'))->assertOk();
    expect(auth('web')->user()->email)->toBe('employee@acme.test');
});

test('livewire requests to a suspended tenant are refused', function () {
    $tenant = tenantWithEmployee('acme');

    $this->post($tenant->url('login'), ['email' => 'employee@acme.test', 'password' => 'password']);

    // A real component snapshot, as the browser would send it back.
    $html = $this->get($tenant->url('settings/profile'))->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);

    $request = fn () => $this->withHeaders(['X-Livewire' => '1'])
        ->postJson(livewireUpdateUrl($tenant), ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]]]);

    $request()->assertOk()->assertJsonStructure(['components']);

    app(SuspendTenant::class)->handle($this->admin, $tenant, 'Contract under review');
    auth()->forgetGuards();

    $request()->assertForbidden();
});

test('suspending one tenant does not affect another', function () {
    $acme = tenantWithEmployee('acme');
    $globex = tenantWithEmployee('globex');

    app(SuspendTenant::class)->handle($this->admin, $acme, 'Contract under review');

    $this->get($acme->url('login'))->assertForbidden();

    $this->post($globex->url('login'), ['email' => 'employee@globex.test', 'password' => 'password'])
        ->assertSessionHasNoErrors();
    $this->get($globex->url('dashboard'))->assertOk();

    expect($globex->refresh()->isSuspended())->toBeFalse();
});

test('suspension and reactivation keep provisioning state and tenant data intact', function () {
    $tenant = tenantWithEmployee('acme');
    $provisionedAt = $tenant->provisioned_at->toIso8601String();
    $snapshot = fn () => $tenant->run(fn () => [
        'users' => User::query()->orderBy('email')->pluck('email')->all(),
        'roles' => User::query()->orderBy('email')->get()->map(fn (User $user) => $user->getRoleNames()->all())->all(),
    ]);
    $before = $snapshot();

    app(SuspendTenant::class)->handle($this->admin, $tenant, 'Contract under review');
    $tenant->refresh();

    expect($tenant->status)->toBe(TenantStatus::Ready)
        ->and($tenant->provisioned_at->toIso8601String())->toBe($provisionedAt)
        ->and($snapshot())->toBe($before);

    $this->actingAs($this->admin, 'platform')
        ->post(route('platform.tenants.reactivate.store', $tenant), ['reason' => 'Contract renewed'])
        ->assertRedirect(route('platform.tenants.show', $tenant));

    $tenant->refresh();

    expect($tenant->isAccessible())->toBeTrue()
        ->and($tenant->suspension_reason)->toBeNull()
        ->and($tenant->status)->toBe(TenantStatus::Ready)
        ->and($tenant->provisioned_at->toIso8601String())->toBe($provisionedAt)
        ->and($snapshot())->toBe($before);

    $entry = PlatformAuditLog::query()->where('action', PlatformAuditAction::TenantReactivated)->sole();
    expect($entry->platform_admin_id)->toBe($this->admin->id)
        ->and($entry->old_values)->toMatchArray(['access_status' => 'suspended', 'suspension_reason' => 'Contract under review'])
        ->and($entry->new_values)->toBe(['access_status' => 'active'])
        ->and($entry->reason)->toBe('Contract renewed');

    $this->post($tenant->url('login'), ['email' => 'employee@acme.test', 'password' => 'password'])
        ->assertSessionHasNoErrors();
    $this->assertAuthenticated('web');
});

test('tenants that are not ready keep returning not found rather than forbidden', function () {
    centralTenant('pending', TenantStatus::Pending);

    $this->get('http://pending.localhost/login')->assertNotFound();
});
