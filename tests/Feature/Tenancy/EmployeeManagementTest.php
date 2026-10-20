<?php

use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\SetEmployeeActivation;
use App\Actions\Employees\SyncEmployeeRoles;
use App\Actions\Employees\UpdateEmployee;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->admin = tenantAdminUser();
});

test('a tenant admin can create an employee in the default branch with a one-time temporary password', function () {
    $loggedMessages = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$loggedMessages) {
        $loggedMessages[] = $event->message.json_encode($event->context);
    });

    $component = Livewire::actingAs($this->admin)
        ->test('pages::employees.index')
        ->call('create')
        ->set('name', 'Bob Builder')
        ->set('email', 'bob@acme.test')
        ->call('save')
        ->assertHasNoErrors();

    $temporaryPassword = $component->get('temporaryPassword');
    $employee = User::query()->where('email', 'bob@acme.test')->sole();

    expect($temporaryPassword)->toBeString()->not->toBeEmpty()
        ->and(Hash::check($temporaryPassword, $employee->password))->toBeTrue()
        ->and($employee->password)->not->toBe($temporaryPassword)
        ->and($employee->primaryBranch->code)->toBe(Branch::DefaultCode)
        ->and($employee->must_change_password)->toBeTrue()
        ->and($employee->is_active)->toBeTrue()
        ->and($employee->getRoleNames()->all())->toBe([TenantRole::Employee->value])
        ->and(implode("\n", $loggedMessages))->not->toContain($temporaryPassword);

    $component->call('forgetTemporaryPassword')->assertSet('temporaryPassword', null);
});

test('a tenant admin can create an employee with selected roles', function () {
    $viewer = Role::create(['name' => 'Viewer', 'guard_name' => 'web']);

    Livewire::actingAs($this->admin)
        ->test('pages::employees.index')
        ->set('name', 'Carla')
        ->set('email', 'carla@acme.test')
        ->set('selectedRoles', [(string) $viewer->id])
        ->call('save')
        ->assertHasNoErrors();

    expect(User::query()->where('email', 'carla@acme.test')->sole()->getRoleNames()->all())->toBe(['Viewer']);
});

test('employee details are validated', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::employees.index')
        ->set('name', '')
        ->set('email', $this->admin->email)
        ->call('save')
        ->assertHasErrors(['name' => 'required', 'email' => 'unique']);
});

test('a tenant admin can edit an employee and change their roles', function () {
    $employee = User::factory()->create(['name' => 'Old Name'])->assignRole(TenantRole::Employee->value);
    $viewer = Role::create(['name' => 'Viewer', 'guard_name' => 'web']);

    Livewire::actingAs($this->admin)
        ->test('pages::employees.index')
        ->call('edit', $employee->id)
        ->assertSet('name', 'Old Name')
        ->set('name', 'New Name')
        ->set('email', 'NEW@acme.test')
        ->set('selectedRoles', [(string) $viewer->id])
        ->call('save')
        ->assertHasNoErrors();

    $employee->refresh();
    expect($employee->name)->toBe('New Name')
        ->and($employee->email)->toBe('new@acme.test')
        ->and($employee->getRoleNames()->all())->toBe(['Viewer']);
});

test('a tenant admin can deactivate and reactivate an employee', function () {
    $employee = User::factory()->create()->assignRole(TenantRole::Employee->value);

    $component = Livewire::actingAs($this->admin)->test('pages::employees.index')
        ->call('deactivate', $employee->id);

    expect($employee->refresh()->is_active)->toBeFalse()
        ->and($employee->deactivated_at)->not->toBeNull();

    $component->call('activate', $employee->id);

    expect($employee->refresh()->is_active)->toBeTrue()
        ->and($employee->deactivated_at)->toBeNull();
});

test('employees can be searched and filtered by status and role', function () {
    $viewer = Role::create(['name' => 'Viewer', 'guard_name' => 'web']);
    User::factory()->create(['name' => 'Dana Active'])->assignRole($viewer);
    User::factory()->inactive()->create(['name' => 'Evan Inactive']);

    $component = Livewire::actingAs($this->admin)->test('pages::employees.index');

    $component->set('search', 'Dana')->assertSee('Dana Active')->assertDontSee('Evan Inactive');
    $component->set('search', '')->set('status', 'inactive')->assertSee('Evan Inactive')->assertDontSee('Dana Active');
    $component->set('status', '')->set('role', (string) $viewer->id)->assertSee('Dana Active')->assertDontSee('Evan Inactive');
});

test('the employee list shows each primary branch and account status', function () {
    User::factory()->inactive()->create(['name' => 'Evan Inactive']);

    $this->actingAs($this->admin)
        ->get(route('employees.index'))
        ->assertOk()
        ->assertSee('Main Branch')
        ->assertSee('Evan Inactive')
        ->assertSee('Inactive');
});

test('an administrator cannot deactivate their own account or change their own roles', function () {
    User::factory()->create()->assignRole(TenantRole::TenantAdmin->value);

    Livewire::actingAs($this->admin)->test('pages::employees.index')
        ->call('deactivate', $this->admin->id);

    expect($this->admin->refresh()->is_active)->toBeTrue()
        ->and($this->admin->can('deactivate', $this->admin))->toBeFalse()
        ->and(fn () => app(SyncEmployeeRoles::class)->handle($this->admin, $this->admin, []))
        ->toThrow(AuthorizationException::class, 'You cannot change your own roles.');

    expect($this->admin->refresh()->isTenantAdmin())->toBeTrue();
});

test('the last active tenant admin cannot be deactivated or demoted', function () {
    // Holds every permission through a custom role, but not the Tenant Admin role.
    $allPermissions = userWithPermissions(TenantPermission::cases());

    expect($this->admin->isLastActiveTenantAdmin())->toBeTrue()
        ->and($allPermissions->can('deactivate', $this->admin))->toBeFalse()
        ->and(fn () => app(SetEmployeeActivation::class)->handle($allPermissions, $this->admin, false))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(SyncEmployeeRoles::class)->handle($allPermissions, $this->admin, []))
        ->toThrow(AuthorizationException::class);

    expect($this->admin->refresh()->is_active)->toBeTrue()
        ->and($this->admin->isTenantAdmin())->toBeTrue();
});

test('changing roles locks the active tenant admins before anything else is read', function () {
    $secondAdmin = User::factory()->create()->assignRole(TenantRole::TenantAdmin->value);
    $employeeRole = Role::findByName(TenantRole::Employee->value, 'web');

    $first = firstQueriesOfTransactions(fn () => app(UpdateEmployee::class)->handle(
        $this->admin, $secondAdmin, ['name' => $secondAdmin->name, 'email' => $secondAdmin->email], [$employeeRole->id],
    ));

    // Otherwise the "last Tenant Admin" count would use a snapshot taken before the lock was
    // granted and miss a concurrent demotion of the other admin.
    expect($first[0] ?? '')->toContain('for update')->toContain('`users`')
        ->and($secondAdmin->refresh()->isTenantAdmin())->toBeFalse();
});

test('the tenant admin count inside a transaction sees a demotion committed after its snapshot', function () {
    $secondAdmin = User::factory()->create()->assignRole(TenantRole::TenantAdmin->value);

    // A second database session plays the other admin's concurrent request.
    config(['database.connections.concurrent' => config('database.connections.tenant')]);

    try {
        DB::transaction(function () use ($secondAdmin): void {
            User::query()->count(); // the first plain read fixes this transaction's snapshot

            DB::connection('concurrent')->table('model_has_roles')->where('model_id', $secondAdmin->id)->delete();

            // A plain read still sees both admins; the locking count sees the committed demotion.
            expect(User::query()->active()->role(TenantRole::TenantAdmin->value)->count())->toBe(2)
                ->and(User::activeTenantAdminCount())->toBe(1)
                ->and($this->admin->isLastActiveTenantAdmin())->toBeTrue();
        });
    } finally {
        DB::purge('concurrent');
    }
});

test('with two active tenant admins one can deactivate the other, leaving a protected last admin', function () {
    $secondAdmin = User::factory()->create()->assignRole(TenantRole::TenantAdmin->value);

    app(SetEmployeeActivation::class)->handle($secondAdmin, $this->admin, false);

    expect($this->admin->refresh()->is_active)->toBeFalse()
        ->and($secondAdmin->refresh()->isLastActiveTenantAdmin())->toBeTrue();
});

test('employees without permission cannot open or use employee management', function () {
    $employee = User::factory()->create()->assignRole(TenantRole::Employee->value);
    $target = User::factory()->create();

    $this->actingAs($employee)->get(route('employees.index'))->assertForbidden();

    Livewire::actingAs($employee)->test('pages::employees.index')->assertForbidden();

    expect(fn () => app(CreateEmployee::class)->handle($employee, ['name' => 'X', 'email' => 'x@acme.test']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetEmployeeActivation::class)->handle($employee, $target, false))
        ->toThrow(AuthorizationException::class);

    expect($target->refresh()->is_active)->toBeTrue();
});
