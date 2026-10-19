<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\EnsureTenantHasActiveSubscription;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;

class TenancyServiceProvider extends ServiceProvider
{
    // By default, no namespace is used to support the callable array syntax.
    public static string $controllerNamespace = '';

    /**
     * @return array<class-string, list<class-string|JobPipeline>>
     */
    public function events(): array
    {
        return [
            // Tenant events
            Events\CreatingTenant::class => [],
            // Provisioning (database creation, migrations, default data) is orchestrated
            // explicitly by App\Actions\Tenancy\ProvisionTenant so it can track status.
            Events\TenantCreated::class => [],
            Events\SavingTenant::class => [],
            Events\TenantSaved::class => [],
            Events\UpdatingTenant::class => [],
            Events\TenantUpdated::class => [],
            Events\DeletingTenant::class => [],
            // Tenant deletion is out of scope; databases are never dropped automatically.
            Events\TenantDeleted::class => [],

            // Domain events
            Events\CreatingDomain::class => [],
            Events\DomainCreated::class => [],
            Events\SavingDomain::class => [],
            Events\DomainSaved::class => [],
            Events\UpdatingDomain::class => [],
            Events\DomainUpdated::class => [],
            Events\DeletingDomain::class => [],
            Events\DomainDeleted::class => [],

            // Database events
            Events\DatabaseCreated::class => [],
            Events\DatabaseMigrated::class => [],
            Events\DatabaseSeeded::class => [],
            Events\DatabaseRolledBack::class => [],
            Events\DatabaseDeleted::class => [],

            // Tenancy events
            Events\InitializingTenancy::class => [],
            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],

            Events\EndingTenancy::class => [],
            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
            ],

            Events\BootstrappingTenancy::class => [],
            Events\TenancyBootstrapped::class => [],
            Events\RevertingToCentralContext::class => [],
            Events\RevertedToCentralContext::class => [],

            // Resource syncing
            Events\SyncedResourceSaved::class => [
                Listeners\UpdateSyncedResource::class,
            ],

            // Fired only when a synced resource is changed in a different DB than the origin DB (to avoid infinite loops)
            Events\SyncedResourceChangedInForeignDatabase::class => [],
        ];
    }

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->bootEvents();

        // Tenant routes are loaded from bootstrap/app.php, after the central routes, so the
        // domain-constrained central routes always take precedence on the central domain.

        $this->makeTenancyMiddlewareHighestPriority();
        $this->configureIdentificationFailures();
        $this->configureLivewireUpdateRoute();
    }

    /**
     * Respond with 404 instead of an exception when no tenant matches the host.
     */
    protected function configureIdentificationFailures(): void
    {
        Middleware\InitializeTenancyBySubdomain::$onFail = function (): never {
            abort(404);
        };
    }

    /**
     * Livewire is only used by the tenant application, so its update endpoint
     * must run inside the tenant context (tenant database, scoped session).
     */
    protected function configureLivewireUpdateRoute(): void
    {
        Livewire::setUpdateRoute(function ($handle, $path) {
            return Route::post($path, $handle)->middleware(['web', 'tenant']);
        });

        // Re-applied to updates of components rendered on routes that use it, so a Livewire
        // request cannot reach operational functionality without an active subscription.
        Livewire::addPersistentMiddleware([EnsureTenantHasActiveSubscription::class]);

        // Livewire remembers, per page path, that persistent middleware already ran and only
        // forgets it in its own test harness. In a long-lived process (Octane, the HTTP test
        // client) the next update of the same page would then skip auth, "can" and the
        // subscription check, so its per-request state is flushed when each request ends.
        $this->app->terminating(fn () => Livewire::flushState());
    }

    protected function bootEvents(): void
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }

                Event::listen($event, $listener);
            }
        }
    }

    protected function makeTenancyMiddlewareHighestPriority(): void
    {
        $tenancyMiddleware = [
            // Even higher priority than the initialization middleware
            Middleware\PreventAccessFromCentralDomains::class,

            Middleware\InitializeTenancyByDomain::class,
            Middleware\InitializeTenancyBySubdomain::class,
            Middleware\InitializeTenancyByDomainOrSubdomain::class,
            Middleware\InitializeTenancyByPath::class,
            Middleware\InitializeTenancyByRequestData::class,
        ];

        // Resolve the bound HTTP kernel singleton; resolving the concrete class would build a second kernel.
        $kernel = $this->app->make(Kernel::class);

        foreach (array_reverse($tenancyMiddleware) as $middleware) {
            $kernel->prependToMiddlewarePriority($middleware);
        }
    }
}
