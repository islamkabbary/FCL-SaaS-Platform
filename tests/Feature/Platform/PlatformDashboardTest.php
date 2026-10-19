<?php

use App\Actions\Platform\SuspendTenant;
use App\Enums\TenantStatus;
use App\Models\PlatformAdmin;

beforeEach(function () {
    $this->admin = PlatformAdmin::factory()->create();
});

test('guests are redirected from the dashboard to the platform login', function () {
    $this->get(route('platform.dashboard'))->assertRedirect(route('platform.login'));
});

test('the dashboard counts tenants by provisioning and access status from the central database', function () {
    centralTenant('ready-one');
    centralTenant('ready-two');
    $suspended = centralTenant('suspended-one');
    centralTenant('pending-one', TenantStatus::Pending);
    centralTenant('provisioning-one', TenantStatus::Provisioning);
    centralTenant('failed-one', TenantStatus::Failed);

    app(SuspendTenant::class)->handle($this->admin, $suspended, 'Unpaid invoices');

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.dashboard'))
        ->assertOk()
        ->assertViewHas('stats', [
            'total' => 6,
            'active' => 2,
            'suspended' => 1,
            'pending' => 1,
            'provisioning' => 1,
            'failed' => 1,
        ]);
});

test('the dashboard shows zero counts when there are no tenants', function () {
    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.dashboard'))
        ->assertOk()
        ->assertViewHas('stats', fn (array $stats): bool => array_sum($stats) === 0);
});

test('the dashboard lists recent administration activity', function () {
    $tenant = centralTenant('acme');
    app(SuspendTenant::class)->handle($this->admin, $tenant, 'Contract under review');

    $this->actingAs($this->admin, 'platform')
        ->get(route('platform.dashboard'))
        ->assertOk()
        ->assertSee('Access suspended')
        ->assertSee('Contract under review')
        ->assertSee($this->admin->email);
});

test('platform admins land on the dashboard after signing in', function () {
    $this->post(route('platform.login.store'), ['email' => $this->admin->email, 'password' => 'password'])
        ->assertRedirect(route('platform.dashboard', absolute: false));
});
