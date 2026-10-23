<?php

use App\Actions\Impersonation\StartSupportSession;
use App\Enums\TenantRole;
use App\Impersonation\RestrictionPolicy;
use App\Models\PlatformAdmin;
use App\Models\SupportSessionEvent;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\WithPagination;

// Milestone 5.2: the restriction policy of impersonated sessions.

describe('pagination calls during support access', function () {
    beforeEach(function () {
        enableSupportAccess();
        $this->tenant = provisionTenant('acme')->tenant;
        [, $token] = startSupportSession(PlatformAdmin::factory()->create(), $this->tenant);
        postHandoff($this->tenant, $token)->assertRedirect();

        PaginatedProbe::$hooks = [];
        Livewire::component('paginated-probe', PaginatedProbe::class);
        Route::middleware(['web', 'tenant', 'auth'])->get('paginated-probe', fn () => Blade::render('<livewire:paginated-probe />'));
    });

    test('a paginator name chosen by the client cannot reach a component\'s update hooks', function (string $method, array $params) {
        livewireRequest($this->tenant, 'paginated-probe', [['method' => $method, 'params' => $params]])->assertForbidden();

        expect(PaginatedProbe::$hooks)->toBe([])
            ->and(SupportSessionEvent::query()->where('type', 'denied')->sole()->action)->toBe('paginated-probe@'.$method);
    })->with([
        'setPage with a custom name' => ['setPage', ['archived', 'status']],
        'gotoPage with a custom name' => ['gotoPage', [2, 'status']],
        'nextPage with a custom name' => ['nextPage', ['status']],
        'resetPage with an empty name' => ['resetPage', ['']],
        'a non-numeric page' => ['setPage', ['archived']],
        'extra parameters' => ['gotoPage', [2, 'page', 'more']],
    ]);

    test('ordinary pagination keeps working', function (string $method, array $params) {
        livewireRequest($this->tenant, 'paginated-probe', [['method' => $method, 'params' => $params]])->assertOk();
    })->with([
        ['setPage', [2]],
        ['gotoPage', ['3', 'page']],
        ['nextPage', []],
        ['previousPage', ['page']],
        ['resetPage', []],
    ]);
});

test('FCL components are recognised under any naming variant', function (string $name, bool $denied) {
    $component = new class extends Component
    {
        public function render(): string
        {
            return '<div></div>';
        }
    };
    $component->setName($name);

    expect(RestrictionPolicy::deniesComponent($component))->toBe($denied);
})->with([
    ['pages::fcl', true],
    ['pages::fcl.jobs.index', true],
    ['fcl.jobs-table', true],
    ['layouts::fcl.sidebar', true],
    ['shipping::fcl.widget', true],
    ['pages::employees.index', false],
    ['pages::fclx', false],
]);

test('FCL resources are denied when passed in arrays or collections, at any depth', function (array $arguments, bool $allowed) {
    expect(RestrictionPolicy::allowsAbility('view', $arguments))->toBe($allowed);
})->with([
    'a class name' => [['App\Models\Fcl\ImportJob'], false],
    'in an array' => [[['App\Models\Fcl\ImportJob']], false],
    'in a collection' => [[collect(['App\Models\Fcl\Party'])], false],
    'nested' => [[[collect([['\App\Models\Fcl\Party']])]], false],
    'a reviewed SaaS model' => [[User::class], true],
    'a collection of SaaS models' => [[collect([User::class])], true],
]);

test('no tenant component is lazy-loaded until lazy loading is reviewed for impersonation', function () {
    // Lazy components are mounted by Livewire's own listener before the impersonation
    // listener can refuse the call (docs/MILESTONE_05_2_REVIEW_HARDENING.md).
    $lazy = collect(File::allFiles(resource_path('views')))
        ->merge(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => preg_match('/#\[Lazy\b|\blazy(=|\s|>|\/)/', (string) file_get_contents($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($lazy)->toBe([]);
});

test('a failed notice to the tenant admins neither fails support access nor leaves tenant context', function () {
    enableSupportAccess();
    Exceptions::fake();
    $tenant = provisionTenant('acme')->tenant;
    $targetId = $tenant->run(fn (): int => User::role(TenantRole::TenantAdmin->value)->firstOrFail()->id);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('notifications table missing'));

    [$session, $token] = app(StartSupportSession::class)->handle(PlatformAdmin::factory()->create(), $tenant, $targetId, 'Ticket 4711: dashboard does not load');

    expect($session->exists)->toBeTrue()
        ->and($token)->not->toBe('')
        ->and(tenancy()->initialized)->toBeFalse();

    Exceptions::assertReported(RuntimeException::class);
});

class PaginatedProbe extends Component
{
    use WithPagination;

    /** @var list<string> */
    public static array $hooks = [];

    protected function updatingStatus(mixed $value): void
    {
        self::$hooks[] = 'updatingStatus';
    }

    protected function updatedStatus(mixed $value): void
    {
        self::$hooks[] = 'updatedStatus';
    }

    public function updating(mixed $name, mixed $value = null): void
    {
        self::$hooks[] = 'updating';
    }

    public function render(): string
    {
        return '<div>paginated</div>';
    }
}
