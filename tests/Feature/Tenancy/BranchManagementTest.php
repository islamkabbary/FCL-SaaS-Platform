<?php

use App\Models\Branch;
use App\Models\User;

beforeEach(function () {
    $this->tenant = actingInTenant();
});

test('tenant users can not create branches', function (bool $isTenantAdmin) {
    $user = User::factory()->create(['is_tenant_admin' => $isTenantAdmin]);

    expect($user->can('create', Branch::class))->toBeFalse();
})->with([
    'tenant admin' => true,
    'employee' => false,
]);

test('there is no endpoint for creating branches', function () {
    $admin = User::query()->where('is_tenant_admin', true)->sole();
    $admin->forceFill(['must_change_password' => false])->save();

    $this->actingAs($admin)
        ->post($this->tenant->url('branches'), ['name' => 'Second Branch', 'code' => 'SECOND'])
        ->assertNotFound();

    expect(Branch::query()->pluck('code')->all())->toBe([Branch::DefaultCode]);
});

test('every employee belongs to a primary branch', function () {
    $branch = Branch::query()->sole();
    $employee = User::factory()->create(['primary_branch_id' => $branch->id]);

    expect($employee->primaryBranch->is($branch))->toBeTrue()
        ->and($branch->employees()->pluck('email'))->toContain($employee->email);
});
