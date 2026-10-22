<?php

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Impersonation\ActiveImpersonation;
use App\Impersonation\DeniedDuringImpersonation;
use App\Impersonation\RestrictionPolicy;
use App\Models\ImpersonationEvent;
use App\Models\PlatformAdmin;
use App\Models\SupportSession;
use App\Models\SupportSessionEvent;
use App\Models\Tenant;
use App\Models\TenantAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Component;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
 * FCL Operations are denied entirely during support access and employee view, reads
 * included, until a support-access policy for them is approved (owner decision PR-08).
 * No FCL screen exists yet, so probe routes and components stand in for them.
 */

beforeEach(function () {
    enableSupportAccess();
    $this->platformAdmin = PlatformAdmin::factory()->create(['email' => 'ops@platform.test']);
    $this->tenant = provisionTenant('acme')->tenant;
    $this->tenantAdminId = $this->tenant->run(fn (): int => tenantAdminUser()->id);

    FclMarkedProbe::$ran = [];

    Livewire::component('fcl-marked-probe', FclMarkedProbe::class);
    Livewire::component('fcl.named-probe', FclNamedProbe::class);

    Route::middleware(['web', 'tenant', 'auth'])->group(function (): void {
        Route::get('probe/marked', fn () => Blade::render('<livewire:fcl-marked-probe />'));
        Route::get('probe/named', fn () => Blade::render('<livewire:fcl.named-probe />'));
        Route::get('fcl/probe', fn () => 'FCL shipment data')->name('fcl.probe.show');
        Route::post('fcl/probe', fn () => 'FCL shipment stored')->name('fcl.probe.store');
        Route::get('unnamed-probe', fn () => 'FCL shipment data')->middleware('impersonation.deny');
    });
    app('router')->getRoutes()->refreshNameLookups();
});

/**
 * Start a support session acting as the Tenant Admin in this test's browser.
 */
function startSupportAsTenantAdmin(): SupportSession
{
    [$session, $token] = startSupportSession(test()->platformAdmin, test()->tenant);
    postHandoff(test()->tenant, $token)->assertRedirect();

    return $session;
}

/**
 * The snapshot of the first Livewire component on a page.
 */
function componentSnapshot(Tenant $tenant, string $path): string
{
    $html = (string) test()->get($tenant->url($path))->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $match);

    return html_entity_decode($match[1], ENT_QUOTES);
}

/**
 * Send a Livewire update for an already captured snapshot.
 *
 * @param  list<array{method: string, params: list<mixed>}>  $calls
 * @param  array<string, mixed>  $updates
 */
function sendSnapshot(Tenant $tenant, string $snapshot, array $calls = [], array $updates = []): TestResponse
{
    $response = test()->withHeaders(['X-Livewire' => '1'])->postJson(
        $tenant->url(route('livewire.update', absolute: false)),
        ['components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => array_map(fn (array $call): array => [...$call, 'metadata' => []], $calls)]]],
    );

    test()->flushHeaders();

    return $response;
}

test('FCL routes work for an authorized user outside an impersonation', function (string $method, string $path) {
    loginToTenant($this->tenant, $this->tenant->run(fn () => User::query()->findOrFail($this->tenantAdminId)));

    $this->call($method, $this->tenant->url($path))->assertOk();
})->with([
    ['GET', 'fcl/probe'],
    ['POST', 'fcl/probe'],
    ['GET', 'unnamed-probe'],
]);

test('FCL routes are refused during support access, reads included, and the refusal is recorded', function (string $method, string $path) {
    startSupportAsTenantAdmin();

    $this->call($method, $this->tenant->url($path))->assertForbidden()->assertDontSee('FCL shipment');

    expect(SupportSessionEvent::query()->where('type', 'denied')->sole())
        ->http_method->toBe($method);
})->with([
    'read by route name' => ['GET', 'fcl/probe'],
    'write by route name' => ['POST', 'fcl/probe'],
    'read by middleware only' => ['GET', 'unnamed-probe'],
]);

test('FCL components cannot be mounted during support access', function (string $path, string $component) {
    startSupportAsTenantAdmin();

    $this->get($this->tenant->url($path))->assertForbidden()->assertDontSee('FCL-PROBE');

    expect(FclMarkedProbe::$ran)->toBe([])
        ->and(SupportSessionEvent::query()->where('type', 'denied')->sole()->action)->toBe($component.'@mount');
})->with([
    'marked with the interface' => ['probe/marked', 'fcl-marked-probe'],
    'recognised by its name' => ['probe/named', 'fcl.named-probe'],
]);

test('an FCL component opened before support access cannot be refreshed, updated or called', function (array $calls, array $updates) {
    loginToTenant($this->tenant, $this->tenant->run(fn () => User::query()->findOrFail($this->tenantAdminId)));
    $snapshot = componentSnapshot($this->tenant, 'probe/marked');
    sendSnapshot($this->tenant, $snapshot, [['method' => '$refresh', 'params' => []]])->assertOk();

    startSupportAsTenantAdmin();
    FclMarkedProbe::$ran = [];

    sendSnapshot($this->tenant, $snapshot, $calls, $updates)->assertForbidden()->assertDontSee('FCL-PROBE');

    // Refused before any of the component's own code (boot, hydrate, updating hooks, render).
    expect(FclMarkedProbe::$ran)->toBe([])
        ->and(SupportSessionEvent::query()->where('type', 'denied')->sole()->action)->toBe('fcl-marked-probe@hydrate');
})->with([
    're-render' => [[['method' => '$refresh', 'params' => []]], []],
    'property update' => [[], ['value' => 'changed']],
    'method call' => [[['method' => 'save', 'params' => []]], []],
]);

test('FCL abilities and resources are denied to the impersonated user, also through the generic view abilities', function () {
    $session = startSupportAsTenantAdmin();
    tenancy()->initialize($this->tenant);
    $admin = User::query()->findOrFail($this->tenantAdminId);
    $record = DB::transaction(fn () => TenantAuditLog::record(FclProbeAuditAction::Probed, null));
    $fclPermissions = array_values(array_filter(
        TenantPermission::values(),
        fn (string $permission): bool => str_starts_with($permission, 'fcl.') || $permission === TenantPermission::AuditView->value,
    ));

    $abilities = fn (): array => [
        ...array_map(fn (string $permission): bool => Gate::forUser($admin)->allows($permission), $fclPermissions),
        Gate::forUser($admin)->allows('viewAny', TenantAuditLog::class),
        Gate::forUser($admin)->allows('view', $record),
    ];

    // Outside the impersonation the Tenant Admin holds all of them.
    expect(array_unique($abilities()))->toBe([true]);

    request()->attributes->set(ActiveImpersonation::RequestAttribute, new ActiveImpersonation($session));

    expect(array_unique($abilities()))->toBe([false])
        // Reviewed SaaS pages keep their read access.
        ->and(Gate::forUser($admin)->allows('viewAny', User::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows(TenantPermission::EmployeesView->value))->toBeTrue();

    // Another user of the company is not affected by someone else's impersonation.
    $other = User::factory()->create()->assignRole(TenantRole::TenantAdmin->value);
    expect(Gate::forUser($other)->allows(TenantPermission::FclJobsCreate->value))->toBeTrue()
        ->and(Gate::forUser($other)->allows('viewAny', TenantAuditLog::class))->toBeTrue();
});

test('the generic view abilities are denied on FCL resources even when their policy would allow them', function () {
    $session = startSupportAsTenantAdmin();
    tenancy()->initialize($this->tenant);
    $admin = User::query()->findOrFail($this->tenantAdminId);
    Gate::policy(FclProbeRecord::class, FclProbeAllowEverythingPolicy::class);

    expect(Gate::forUser($admin)->allows('viewAny', FclProbeRecord::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('view', new FclProbeRecord))->toBeTrue();

    request()->attributes->set(ActiveImpersonation::RequestAttribute, new ActiveImpersonation($session));

    expect(Gate::forUser($admin)->allows('viewAny', FclProbeRecord::class))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', new FclProbeRecord))->toBeFalse();
});

test('FCL Operations are refused during an employee view as well', function () {
    $employeeId = $this->tenant->run(function (): int {
        $role = Role::create(['name' => 'Documentation', 'guard_name' => 'web'])
            ->givePermissionTo([TenantPermission::FclJobsView->value, TenantPermission::FclJobsCreate->value, TenantPermission::AuditView->value]);

        return User::factory()->create(['email' => 'docs@acme.test'])->assignRole($role)->id;
    });

    loginToTenant($this->tenant, $this->tenant->run(fn () => User::query()->findOrFail($this->tenantAdminId)));
    startEmployeeView($this->tenant, $employeeId)->assertOk();

    $this->get($this->tenant->url('fcl/probe'))->assertForbidden();
    $this->get($this->tenant->url('probe/marked'))->assertForbidden();

    expect($this->tenant->run(fn () => ImpersonationEvent::query()->where('type', 'denied')->pluck('action')->all()))
        ->toBe(['fcl.probe.show', 'fcl-marked-probe@mount'])
        ->and(FclMarkedProbe::$ran)->toBe([]);
});

test('the restriction policy recognises FCL resources and marked classes', function (mixed $resource, bool $denied) {
    expect(RestrictionPolicy::deniesResource(value($resource)))->toBe($denied);
})->with([
    'FCL model class name' => ['App\\Models\\Fcl\\ImportJob', true],
    'FCL model class name with leading backslash' => ['\\App\\Models\\Fcl\\Party', true],
    'marked model class' => [TenantAuditLog::class, true],
    'marked model instance' => [fn () => new TenantAuditLog, true],
    'tenant user' => [User::class, false],
    'role' => [Role::class, false],
    'not a class' => [42, false],
]);

/**
 * A resource marked as denied whose own policy allows everything, so only the
 * impersonation restriction can refuse it.
 */
class FclProbeRecord extends Model implements DeniedDuringImpersonation {}

class FclProbeAllowEverythingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FclProbeRecord $record): bool
    {
        return true;
    }
}

enum FclProbeAuditAction: string
{
    case Probed = 'probe.probed';
}

class FclMarkedProbe extends Component implements DeniedDuringImpersonation
{
    /** @var list<string> */
    public static array $ran = [];

    public string $value = '';

    public function boot(): void
    {
        self::$ran[] = 'boot';
    }

    public function mount(): void
    {
        self::$ran[] = 'mount';
    }

    public function hydrate(): void
    {
        self::$ran[] = 'hydrate';
    }

    public function updatingValue(): void
    {
        self::$ran[] = 'updating';
    }

    public function save(): void
    {
        self::$ran[] = 'save';
    }

    public function render(): string
    {
        self::$ran[] = 'render';

        return '<div>Confidential shipment FCL-PROBE</div>';
    }
}

class FclNamedProbe extends Component
{
    public function render(): string
    {
        FclMarkedProbe::$ran[] = 'named render';

        return '<div>Named probe FCL-PROBE</div>';
    }
}
