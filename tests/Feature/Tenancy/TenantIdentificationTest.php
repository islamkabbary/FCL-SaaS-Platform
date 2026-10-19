<?php

use App\Enums\TenantStatus;
use App\Http\Middleware\EnsureTenantIsReady;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\Tenant;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Session\Middleware\StartSession;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\ScopeSessions;

test('a tenant is resolved from its subdomain', function () {
    $acme = provisionTenant('acme')->tenant;
    $globex = provisionTenant('globex')->tenant;

    $this->get($globex->url('login'))->assertOk();
    expect(tenant('id'))->toBe($globex->id);

    // Browsers keep host-only cookies per subdomain; the test client shares one session.
    $this->flushSession();

    $this->get($acme->url('login'))->assertOk();
    expect(tenant('id'))->toBe($acme->id);
});

test('the http kernel runs tenant middleware in a safe order', function () {
    $priority = app(Kernel::class)->getMiddlewarePriority();
    $position = fn (string $middleware): int|false => array_search($middleware, $priority, true);

    expect($position(InitializeTenancyBySubdomain::class))->toBeInt()
        ->toBeLessThan($position(EnsureTenantIsReady::class))
        ->and($position(EnsureTenantIsReady::class))->toBeLessThan($position(StartSession::class))
        ->and($position(StartSession::class))->toBeLessThan($position(ScopeSessions::class))
        ->and($position(ScopeSessions::class))->toBeLessThan($position(EnsureUserIsActive::class))
        ->and($position(EnsureUserIsActive::class))->toBeLessThan($position(AuthenticatesRequests::class));
});

test('an unknown subdomain returns not found', function () {
    $this->get('http://unknown.localhost/login')->assertNotFound();
});

test('a tenant that is not ready returns not found', function (TenantStatus $status) {
    $tenant = Tenant::create(['name' => 'Pending Co', 'status' => $status]);
    $tenant->domains()->create(['domain' => 'pending']);

    $this->get('http://pending.localhost/login')->assertNotFound();
})->with([TenantStatus::Pending, TenantStatus::Provisioning, TenantStatus::Failed]);

test('tenant routes are not served on the central domain', function (string $path) {
    provisionTenant('acme');

    $this->get("http://localhost/{$path}")->assertNotFound();
})->with(['login', 'dashboard', 'settings/profile', 'forgot-password']);
