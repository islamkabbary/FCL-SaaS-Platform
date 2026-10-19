<?php

use App\Actions\Billing\RequestSeatChange;
use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\SetEmployeeActivation;
use App\Actions\Employees\SyncEmployeeRoles;
use App\Actions\Roles\SaveRole;
use App\Enums\PlatformAuditAction;
use App\Enums\SeatRequestType;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Impersonation\ActiveImpersonation;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\SeatChangeRequest;
use App\Models\SupportSession;
use App\Models\SupportSessionEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    enableSupportAccess();
    $this->platformAdmin = PlatformAdmin::factory()->create(['email' => 'ops@platform.test']);
    $this->tenant = provisionTenant('acme')->tenant;

    $ids = $this->tenant->run(function (): array {
        $admin = User::role(TenantRole::TenantAdmin->value)->firstOrFail();
        $employee = User::factory()->create(['email' => 'clerk@acme.test'])->assignRole(TenantRole::Employee->value);

        return [$admin->id, $employee->id, $admin->password];
    });
    [$this->tenantAdminId, $this->employeeId, $this->adminPasswordHash] = $ids;

    // A support session acting as the Tenant Admin, who holds every permission.
    [$this->supportSession, $token] = startSupportSession($this->platformAdmin, $this->tenant);
    postHandoff($this->tenant, $token)->assertRedirect();
});

/**
 * The tenant's state that no impersonated operation may change.
 *
 * @return array<string, mixed>
 */
function protectedTenantState(): array
{
    return test()->tenant->run(fn (): array => [
        'users' => User::query()->orderBy('id')->get(['id', 'name', 'email', 'password', 'is_active', 'must_change_password'])->toArray(),
        'roles' => Role::query()->with('permissions:id,name')->orderBy('id')->get()->map(fn (Role $role) => [$role->name, $role->permissions->pluck('name')->all()])->all(),
        'assignments' => User::query()->with('roles:id,name')->orderBy('id')->get()->map(fn (User $user) => $user->roles->pluck('name')->all())->all(),
    ]);
}

test('read-only pages stay available during support access', function (string $path) {
    $this->get($this->tenant->url($path))->assertOk()->assertSee('Impersonation active');
})->with(['dashboard', 'employees', 'roles', 'subscription', 'settings/profile', 'settings/security']);

test('the access history is not available to an impersonated session', function () {
    $this->get($this->tenant->url('access-history'))->assertForbidden();
});

test('livewire operations are refused, recorded, and change nothing', function (string $path, string $method, array $params, array $updates, ?string $component) {
    $before = protectedTenantState();

    livewireRequest($this->tenant, $path, [['method' => $method, 'params' => $params]], $updates, $component)->assertForbidden();

    expect(protectedTenantState())->toEqual($before)
        ->and(SeatChangeRequest::query()->count())->toBe(0)
        ->and(SupportSessionEvent::query()->where('type', 'denied')->sole()->action)->toEndWith('@'.$method);
})->with([
    'create an employee' => ['employees', 'save', [], ['name' => 'Mallory', 'email' => 'mallory@acme.test'], null],
    'open the create form' => ['employees', 'create', [], [], null],
    'edit an employee' => ['employees', 'edit', [2], [], null],
    'deactivate an employee' => ['employees', 'deactivate', [2], [], null],
    'activate an employee' => ['employees', 'activate', [2], [], null],
    'start another impersonation' => ['employees', 'startImpersonation', [], ['impersonationTargetId' => 2, 'impersonationReason' => 'Nested attempt', 'impersonationPassword' => 'password'], null],
    'create a role' => ['roles', 'save', [], [], null],
    'delete a role' => ['roles', 'delete', [2], [], null],
    'request seats' => ['subscription', 'requestIncrease', [], ['increaseSeats' => '3'], null],
    'request a reduction' => ['subscription', 'requestReduction', [], [], null],
    'cancel a seat request' => ['subscription', 'cancelRequest', [1], [], null],
    'change name or email' => ['settings/profile', 'updateProfileInformation', [], ['email' => 'mallory@evil.test'], null],
    'change the password' => ['settings/security', 'updatePassword', [], ['current_password' => 'x', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'], null],
    'delete the account' => ['settings/profile', 'deleteUser', [], ['password' => 'x'], 'pages::settings.delete-user-modal'],
]);

test('non-GET requests outside the allowlist are refused and recorded', function (string $path) {
    $this->post($this->tenant->url($path), ['password' => 'password'])->assertForbidden();

    expect(SupportSessionEvent::query()->where('type', 'denied')->where('http_method', 'POST')->count())->toBe(1);
})->with([
    'confirm the target\'s password' => ['user/confirm-password'],
    'start another support session' => ['support/handoff'],
]);

test('the platform and its billing endpoints are not reachable from the tenant session', function (string $method, string $path) {
    $this->call($method, $this->tenant->url($path))->assertNotFound();
})->with([
    ['GET', 'admin/plans'],
    ['POST', 'admin/charges/1/payments'],
    ['POST', 'admin/seat-requests/1/approve'],
    ['POST', 'admin/tenants/x/subscription/renew'],
]);

test('backend actions refuse the impersonated user even when called directly', function () {
    $session = SupportSession::query()->sole();
    request()->attributes->set(ActiveImpersonation::RequestAttribute, new ActiveImpersonation($session));
    tenancy()->initialize($this->tenant);

    $admin = User::query()->findOrFail($this->tenantAdminId);
    $employee = User::query()->findOrFail($this->employeeId);

    expect(Gate::forUser($admin)->allows(TenantPermission::EmployeesView->value))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('viewAny', User::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows(TenantPermission::EmployeesCreate->value))->toBeFalse()
        ->and(Gate::forUser($admin)->allows(TenantPermission::BillingManage->value))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('deactivate', $employee))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('impersonate', $employee))->toBeFalse()
        ->and(fn () => app(CreateEmployee::class)->handle($admin, ['name' => 'Mallory', 'email' => 'mallory@acme.test']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetEmployeeActivation::class)->handle($admin, $employee, false))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SyncEmployeeRoles::class)->handle($admin, $employee, [1]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveRole::class)->handle($admin, null, 'Escalated', TenantPermission::values()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RequestSeatChange::class)->handle($admin, SeatRequestType::Increase, 5))->toThrow(AuthorizationException::class)
        ->and(fn () => PlatformAuditLog::recordForTenantUser($admin, $this->tenant, PlatformAuditAction::SeatIncreaseRequested))->toThrow(LogicException::class);

    // Other users are not affected by someone else's impersonation.
    expect(Gate::forUser($employee)->allows(TenantPermission::EmployeesView->value))->toBeFalse()
        ->and($employee->is_active)->toBeTrue();
});

test('components not reviewed for impersonation refuse property updates and methods', function () {
    // A component added later (e.g. an FCL Operations screen) that nobody reviewed yet.
    Livewire::component('impersonation-probe', ImpersonationProbe::class);
    Route::middleware(['web', 'tenant', 'auth'])->get('impersonation-probe', fn () => Blade::render('<livewire:impersonation-probe />'));

    livewireRequest($this->tenant, 'impersonation-probe', updates: ['value' => 'changed'])->assertForbidden();
    livewireRequest($this->tenant, 'impersonation-probe', [['method' => 'save', 'params' => []]])->assertForbidden();
    livewireRequest($this->tenant, 'impersonation-probe', [['method' => '$refresh', 'params' => []]])->assertOk();

    expect(ImpersonationProbe::$saved)->toBeFalse()
        ->and(SupportSessionEvent::query()->where('type', 'denied')->pluck('action')->all())
        ->toBe(['impersonation-probe#value', 'impersonation-probe@save']);
});

test('impersonation state does not leak into later requests of the same process', function () {
    $this->get($this->tenant->url('employees'))->assertOk()->assertSee('Impersonation active');

    // A different browser: an ordinary Tenant Admin session in the same PHP process.
    $this->flushSession();
    auth()->forgetGuards();
    $owner = $this->tenant->run(fn () => User::factory()->create(['email' => 'owner@acme.test'])->assignRole(TenantRole::TenantAdmin->value));
    loginToTenant($this->tenant, $owner);

    $this->get($this->tenant->url('employees'))->assertOk()->assertDontSee('Impersonation active');
    livewireRequest($this->tenant, 'employees', [['method' => 'deactivate', 'params' => [$this->employeeId]]])->assertOk();

    expect($this->tenant->run(fn () => User::query()->findOrFail($this->employeeId)->is_active))->toBeFalse();
});

test('an impersonation in one tenant does not restrict another tenant', function () {
    $globex = provisionTenant('globex')->tenant;
    $this->get($this->tenant->url('employees'))->assertSee('Impersonation active');

    $this->flushSession();
    auth()->forgetGuards();
    $globexAdmin = $globex->run(fn () => tenantAdminUser());
    $globexEmployee = $globex->run(fn () => User::factory()->create()->assignRole(TenantRole::Employee->value));
    loginToTenant($globex, $globexAdmin);

    livewireRequest($globex, 'employees', [['method' => 'deactivate', 'params' => [$globexEmployee->id]]])->assertOk();
    expect($globex->run(fn () => $globexEmployee->fresh()->is_active))->toBeFalse();
});

test('a reused session immediately reflects changed permissions', function () {
    // An employee view whose role loses a permission while the view is open.
    $this->post($this->tenant->url('impersonation/end'));
    $this->flushSession();
    auth()->forgetGuards();

    $role = $this->tenant->run(fn () => Role::findByName(TenantRole::Employee->value, 'web')->givePermissionTo(TenantPermission::EmployeesView->value));
    $owner = $this->tenant->run(fn () => User::query()->findOrFail($this->tenantAdminId));
    loginToTenant($this->tenant, $owner);
    startEmployeeView($this->tenant, $this->employeeId)->assertOk();

    $this->get($this->tenant->url('employees'))->assertOk();

    $this->tenant->run(fn () => $role->revokePermissionTo(TenantPermission::EmployeesView->value));

    $this->get($this->tenant->url('employees'))->assertForbidden();
});

class ImpersonationProbe extends Component
{
    public static bool $saved = false;

    public string $value = '';

    public function save(): void
    {
        self::$saved = true;
    }

    public function render(): string
    {
        return '<div>{{ $value }}</div>';
    }
}
