<?php

use App\Enums\TenantPermission;
use App\Models\ImpersonationEvent;
use App\Models\PlatformAdmin;
use App\Models\SupportSessionEvent;

// Milestone 5.2: the access history records every page an impersonated session saw,
// after authorization, so it can be neither hidden nor misleading.

beforeEach(function () {
    enableSupportAccess();
    $this->platformAdmin = PlatformAdmin::factory()->create(['email' => 'ops@platform.test']);
    $this->tenant = provisionTenant('acme')->tenant;
});

function supportEvents(): array
{
    return SupportSessionEvent::query()->orderBy('id')->get(['type', 'action', 'http_method'])->toArray();
}

test('a page view with a client-supplied X-Livewire header is still recorded', function () {
    [, $token] = startSupportSession($this->platformAdmin, $this->tenant);
    postHandoff($this->tenant, $token)->assertRedirect();

    $this->withHeader('X-Livewire', '1')->get($this->tenant->url('employees'))->assertOk();

    expect(supportEvents())->toContain(['type' => 'viewed', 'action' => 'employees.index', 'http_method' => 'GET']);
});

test('a page refused by authorization is recorded as denied, not as viewed', function () {
    [, $token] = startSupportSession($this->platformAdmin, $this->tenant);
    postHandoff($this->tenant, $token)->assertRedirect();

    $this->get($this->tenant->url('access-history'))->assertForbidden();

    $events = collect(supportEvents())->where('action', 'access-history.index');
    expect($events->pluck('type')->all())->toBe(['denied']);
});

test('an employee view records pages viewed with any headers', function () {
    $tenant = actingInTenant('globex');
    $admin = tenantAdminUser();
    $employee = userWithPermissions([TenantPermission::EmployeesView]);
    loginToTenant($tenant, $admin);
    startEmployeeView($tenant, $employee->id)->assertOk();

    $this->withHeader('X-Livewire', '1')->get($tenant->url('employees'))->assertOk();

    expect(ImpersonationEvent::query()->where('type', 'viewed')->where('action', 'employees.index')->count())->toBe(1);
});

test('a Livewire update refused by the page authorization is recorded as denied', function () {
    // A page the Tenant Admin opened before the support session started.
    loginToTenant($this->tenant, $this->tenant->run(fn () => tenantAdminUser()));
    $html = (string) $this->get($this->tenant->url('access-history'))->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $match);

    [, $token] = startSupportSession($this->platformAdmin, $this->tenant);
    postHandoff($this->tenant, $token)->assertRedirect();

    // Livewire re-applies the page's "can:" check to the update, which refuses it.
    $this->withHeaders(['X-Livewire' => '1'])->postJson($this->tenant->url(route('livewire.update', absolute: false)), [
        'components' => [['snapshot' => html_entity_decode($match[1], ENT_QUOTES), 'updates' => [], 'calls' => [['method' => '$refresh', 'params' => [], 'metadata' => []]]]],
    ])->assertForbidden();

    expect(supportEvents())->toBe([['type' => 'denied', 'action' => 'livewire.update: pages::access-history.index', 'http_method' => 'POST']]);
});
