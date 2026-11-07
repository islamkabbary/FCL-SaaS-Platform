<?php

use App\Models\PlatformAdmin;

test('the schema viewer is enabled and open to platform admins only', function () {
    expect(config('truss.enabled'))->toBeTrue();

    $this->get(route('truss.index'))->assertRedirect(route('platform.login'));

    $this->actingAs(PlatformAdmin::factory()->create(), 'platform')
        ->get(route('truss.index'))
        ->assertOk();
});

test('a tenant user cannot open the schema viewer', function () {
    $tenant = actingInTenant();
    $this->actingAs(superAdminUser(), 'web');

    $this->get(route('truss.index'))->assertRedirect();
    expect(Gate::forUser(superAdminUser())->allows('viewTruss'))->toBeFalse();
});
