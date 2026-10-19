<?php

use App\Actions\Employees\SetEmployeeActivation;
use App\Actions\Impersonation\StartEmployeeImpersonation;
use App\Enums\ImpersonationEndReason;
use App\Enums\ImpersonationStatus;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Exceptions\ImpersonationRefused;
use App\Impersonation\Impersonator;
use App\Models\ImpersonationEvent;
use App\Models\ImpersonationSession;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->admin = tenantAdminUser();
    $this->employee = userWithPermissions([TenantPermission::EmployeesView], ['email' => 'clerk@acme.test', 'name' => 'Clara Clerk']);
});

test('a tenant admin can view the application as a lower-privileged employee', function () {
    loginToTenant($this->tenant, $this->admin);

    startEmployeeView($this->tenant, $this->employee->id)->assertOk();

    $this->get($this->tenant->url('dashboard'))
        ->assertOk()
        ->assertSee('Impersonation active')
        ->assertSee('Tenant Admin: Admin of acme')
        ->assertSee('Viewing as Clara Clerk')
        ->assertSee('End impersonation');

    $session = ImpersonationSession::query()->sole();
    expect(auth('web')->id())->toBe($this->employee->id)
        ->and($session->impersonator_user_id)->toBe($this->admin->id)
        ->and($session->impersonator_email)->toBe('admin@acme.test')
        ->and($session->target_user_id)->toBe($this->employee->id)
        ->and($session->reason)->toBe('Reproduce ticket 88 for this employee')
        ->and($session->status())->toBe(ImpersonationStatus::Active)
        ->and($session->expires_at->diffInMinutes($session->started_at, true))->toEqual(15.0);
});

test('the employee view uses the employee\'s permissions and changes nothing in their account', function () {
    $before = $this->employee->only(['password', 'remember_token', 'updated_at']);
    loginToTenant($this->tenant, $this->admin);
    startEmployeeView($this->tenant, $this->employee->id);

    // The employee may view employees but not roles.
    $this->get($this->tenant->url('employees'))->assertOk();
    $this->get($this->tenant->url('roles'))->assertForbidden();

    expect($this->employee->fresh()->only(['password', 'remember_token', 'updated_at']))->toEqual($before);
});

test('the tenant admin\'s own password is required', function () {
    loginToTenant($this->tenant, $this->admin);

    startEmployeeView($this->tenant, $this->employee->id, password: 'wrong')
        ->assertOk()
        ->assertSee('The password is incorrect.');

    expect(ImpersonationSession::query()->count())->toBe(0);
    auth()->forgetGuards();
    $this->get($this->tenant->url('dashboard'))->assertDontSee('Impersonation active');
    expect(auth('web')->id())->toBe($this->admin->id);
});

test('repeated wrong passwords are rate limited', function () {
    $action = app(StartEmployeeImpersonation::class);
    $request = request();
    $request->setLaravelSession(app('session.store'));

    foreach (range(1, 5) as $attempt) {
        try {
            $action->handle($request, $this->admin, $this->employee->id, 'Reproduce a problem', 'wrong');
        } catch (ValidationException) {
        }
    }

    expect(fn () => $action->handle($request, $this->admin, $this->employee->id, 'Reproduce a problem', 'password'))
        ->toThrow(ValidationException::class, 'Too many attempts');
});

test('a reason is required', function () {
    loginToTenant($this->tenant, $this->admin);

    startEmployeeView($this->tenant, $this->employee->id, reason: '')->assertOk();

    expect(ImpersonationSession::query()->count())->toBe(0);
});

test('only active tenant admins can start an employee view', function () {
    $manager = userWithPermissions(TenantPermission::cases(), ['email' => 'manager@acme.test']);

    expect(Gate::forUser($manager)->allows('impersonate', $this->employee))->toBeFalse()
        ->and(Gate::forUser($this->employee)->allows('impersonate', $manager))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('impersonate', $this->employee))->toBeTrue();
});

test('tenant admins and users with equal or higher privileges cannot be targeted', function () {
    $otherAdmin = User::factory()->create(['email' => 'other-admin@acme.test'])->assignRole(TenantRole::TenantAdmin->value);
    $equal = userWithPermissions(TenantPermission::cases(), ['email' => 'equal@acme.test']);
    $inactive = userWithPermissions([TenantPermission::EmployeesView], ['email' => 'gone@acme.test', 'is_active' => false]);

    foreach ([$otherAdmin, $equal, $inactive, $this->admin] as $target) {
        expect(Gate::forUser($this->admin)->allows('impersonate', $target))->toBeFalse();
    }

    loginToTenant($this->tenant, $this->admin);

    // Even when the target id is tampered with in the Livewire payload, the action refuses.
    startEmployeeView($this->tenant, $otherAdmin->id)->assertOk()->assertSee('You cannot view the application as another Tenant Admin.');
    startEmployeeView($this->tenant, $equal->id)->assertOk()->assertSee('privileges equal to or higher than yours');
    startEmployeeView($this->tenant, $inactive->id)->assertOk()->assertSee('Only active employees can be selected.');

    expect(ImpersonationSession::query()->count())->toBe(0)
        ->and(auth('web')->id())->toBe($this->admin->id);

    $this->get($this->tenant->url('employees'))
        ->assertSee('View as')
        ->assertSee('clerk@acme.test');
});

test('users of another tenant cannot be targeted', function () {
    $globex = provisionTenant('globex')->tenant;
    $globexEmployee = $globex->run(fn () => User::factory()->count(5)->create()->last());

    tenancy()->initialize($this->tenant);
    $this->admin->forceFill(['password' => 'password'])->save();

    expect(User::query()->whereKey($globexEmployee->id)->exists())->toBeFalse()
        ->and(fn () => app(StartEmployeeImpersonation::class)->handle(tap(request())->setLaravelSession(app('session.store')), $this->admin, $globexEmployee->id, 'Cross tenant attempt', 'password'))
        ->toThrow(ImpersonationRefused::class, 'does not exist');
});

test('ending the view restores the tenant admin with a new session id', function () {
    loginToTenant($this->tenant, $this->admin);
    startEmployeeView($this->tenant, $this->employee->id);
    $impersonatedSessionId = session()->getId();

    $this->post($this->tenant->url('impersonation/end'))
        ->assertRedirect($this->tenant->url('employees'))
        ->assertSessionHas('status', 'You are back in your own account.');

    $session = ImpersonationSession::query()->sole();
    expect(auth('web')->id())->toBe($this->admin->id)
        ->and(session()->getId())->not->toBe($impersonatedSessionId)
        ->and(session()->has(Impersonator::SessionKey))->toBeFalse()
        ->and($session->end_reason)->toBe(ImpersonationEndReason::EndedByActor)
        ->and($session->ended_by_email)->toBe('admin@acme.test');

    auth()->forgetGuards();
    $this->get($this->tenant->url('dashboard'))->assertOk()->assertDontSee('Impersonation active');
});

test('the view ends at its time limit and returns to the tenant admin', function () {
    loginToTenant($this->tenant, $this->admin);
    startEmployeeView($this->tenant, $this->employee->id);

    $this->travel(16)->minutes();

    $this->get($this->tenant->url('dashboard'))->assertRedirect($this->tenant->url('employees'));
    expect(ImpersonationSession::query()->sole()->end_reason)->toBe(ImpersonationEndReason::Expired)
        ->and(auth('web')->id())->toBe($this->admin->id);
});

test('livewire requests after the time limit are refused rather than run as anyone', function () {
    loginToTenant($this->tenant, $this->admin);
    startEmployeeView($this->tenant, $this->employee->id);
    $html = $this->get($this->tenant->url('employees'))->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', (string) $html, $matches);

    $this->travel(16)->minutes();

    $this->withHeaders(['X-Livewire' => '1'])->postJson(
        $this->tenant->url(route('livewire.update', absolute: false)),
        ['components' => [['snapshot' => html_entity_decode($matches[1], ENT_QUOTES), 'updates' => [], 'calls' => [['method' => 'deactivate', 'params' => [$this->employee->id], 'metadata' => []]]]]],
    )->assertForbidden();

    expect($this->employee->fresh()->is_active)->toBeTrue();
});

test('deactivating the employee ends the view and returns to the tenant admin', function () {
    loginToTenant($this->tenant, $this->admin);
    startEmployeeView($this->tenant, $this->employee->id);

    $otherAdmin = User::factory()->create()->assignRole(TenantRole::TenantAdmin->value);
    app(SetEmployeeActivation::class)->handle($otherAdmin, $this->employee, false);

    expect(ImpersonationSession::query()->sole()->end_reason)->toBe(ImpersonationEndReason::TargetDeactivated);

    auth()->forgetGuards();
    $this->get($this->tenant->url('dashboard'))->assertRedirect($this->tenant->url('employees'));
    expect(auth('web')->id())->toBe($this->admin->id);
});

test('an initiator who is no longer a tenant admin is logged out instead of restored', function () {
    loginToTenant($this->tenant, $this->admin);
    startEmployeeView($this->tenant, $this->employee->id);

    User::factory()->create()->assignRole(TenantRole::TenantAdmin->value);
    $this->admin->syncRoles([TenantRole::Employee->value]);

    auth()->forgetGuards();
    $this->get($this->tenant->url('dashboard'))->assertRedirect($this->tenant->url('login'));

    expect(ImpersonationSession::query()->sole()->end_reason)->toBe(ImpersonationEndReason::ActorUnauthorized)
        ->and(auth('web')->check())->toBeFalse();
});

test('another tenant admin can end an employee view from the access history', function () {
    loginToTenant($this->tenant, $this->admin);
    startEmployeeView($this->tenant, $this->employee->id);
    $session = ImpersonationSession::query()->sole();

    $otherAdmin = User::factory()->create(['email' => 'owner@acme.test'])->assignRole(TenantRole::TenantAdmin->value);
    Livewire::actingAs($otherAdmin)
        ->test('pages::access-history.index')
        ->assertSee('clerk@acme.test')
        ->call('endEmployeeView', $session->id);

    expect($session->refresh()->status())->toBe(ImpersonationStatus::Revoked)
        ->and($session->ended_by_email)->toBe('owner@acme.test');

    auth()->forgetGuards();
    $this->get($this->tenant->url('dashboard'))->assertRedirect($this->tenant->url('employees'));
    expect(auth('web')->id())->toBe($this->admin->id);
});

test('nested impersonation is refused', function () {
    $second = userWithPermissions([TenantPermission::EmployeesView], ['email' => 'second@acme.test']);
    loginToTenant($this->tenant, $this->admin);
    startEmployeeView($this->tenant, $this->employee->id);

    startEmployeeView($this->tenant, $second->id)->assertForbidden();
    livewireRequest($this->tenant, 'employees', [['method' => 'confirmImpersonation', 'params' => [$second->id]]])->assertForbidden();
    postHandoff($this->tenant, str_repeat('a', 64))->assertForbidden();

    expect(ImpersonationSession::query()->count())->toBe(1)
        ->and(auth('web')->id())->toBe($this->employee->id);
});

test('the impersonated identity cannot impersonate back to its own initiator', function () {
    loginToTenant($this->tenant, $this->admin);
    startEmployeeView($this->tenant, $this->employee->id);

    expect(fn () => app(StartEmployeeImpersonation::class)->handle(tap(request())->setLaravelSession(app('session.store')), $this->employee, $this->admin->id, 'Circular', 'password'))
        ->toThrow(ImpersonationRefused::class, 'End the current impersonation session first');
});

test('the view is recorded in the tenant only, with the original actor and what was viewed or refused', function () {
    loginToTenant($this->tenant, $this->admin);
    startEmployeeView($this->tenant, $this->employee->id);

    $this->get($this->tenant->url('employees'))->assertOk();
    livewireRequest($this->tenant, 'employees', [['method' => 'deactivate', 'params' => [$this->employee->id]]])->assertForbidden();

    $events = ImpersonationEvent::query()->orderBy('id')->get(['type', 'action', 'http_method'])->toArray();
    expect($events)->toContain(['type' => 'viewed', 'action' => 'employees.index', 'http_method' => 'GET'])
        ->and($events)->toContain(['type' => 'denied', 'action' => 'pages::employees.index@deactivate', 'http_method' => 'POST']);

    // Nothing is attributed to the employee in the central audit trail.
    expect(PlatformAuditLog::query()->count())->toBe(0);
});

test('the feature can be disabled', function () {
    config(['impersonation.employee.enabled' => false]);

    expect(Gate::forUser($this->admin)->allows('impersonate', $this->employee))->toBeFalse();

    loginToTenant($this->tenant, $this->admin);
    $this->get($this->tenant->url('employees'))->assertDontSee('View as');
});

test('disabling the feature ends running views', function () {
    loginToTenant($this->tenant, $this->admin);
    startEmployeeView($this->tenant, $this->employee->id);

    config(['impersonation.employee.enabled' => false]);

    $this->get($this->tenant->url('dashboard'))->assertRedirect($this->tenant->url('employees'));
    expect(ImpersonationSession::query()->sole()->end_reason)->toBe(ImpersonationEndReason::FeatureDisabled);
});

test('an employee with a temporary password can be viewed without being forced to change it', function () {
    $this->employee->forceFill(['must_change_password' => true])->save();
    $passwordHash = $this->employee->password;
    loginToTenant($this->tenant, $this->admin);

    startEmployeeView($this->tenant, $this->employee->id);

    $this->get($this->tenant->url('dashboard'))->assertOk()->assertSee('Impersonation active');
    expect($this->employee->fresh()->password)->toBe($passwordHash)
        ->and($this->employee->fresh()->must_change_password)->toBeTrue();
});
