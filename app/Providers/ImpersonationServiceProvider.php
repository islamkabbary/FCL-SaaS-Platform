<?php

namespace App\Providers;

use App\Impersonation\ActiveImpersonation;
use App\Impersonation\EmployeeImpersonationRules;
use App\Impersonation\RestrictionPolicy;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Restricted interactive impersonation: the Livewire and authorization layers of the
 * restriction policy (the HTTP layer is EnforceImpersonation), and who may impersonate.
 */
class ImpersonationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->defineGates();
        $this->restrictAbilities();
        $this->restrictLivewire();
    }

    protected function defineGates(): void
    {
        // Central platform: Platform Admins only. Other identities are denied explicitly
        // (a type hint alone would make Laravel throw for an authenticated tenant user).
        Gate::define('start-support-session', fn (mixed $user): bool => $user instanceof PlatformAdmin && (bool) config('impersonation.support.enabled'));
        Gate::define('view-support-sessions', fn (mixed $user): bool => $user instanceof PlatformAdmin);
        Gate::define('revoke-support-session', fn (mixed $user): bool => $user instanceof PlatformAdmin);

        // Tenant: Tenant Admins only.
        Gate::define('impersonate', function (mixed $actor, mixed $employee): Response {
            if (! config('impersonation.employee.enabled')) {
                return Response::denyAsNotFound();
            }

            if (! $actor instanceof User || ! $employee instanceof User) {
                return Response::deny();
            }

            $denial = app(EmployeeImpersonationRules::class)->denialReason($actor, $employee);

            return $denial === null ? Response::allow() : Response::deny($denial);
        });

        Gate::define('view-access-history', fn (mixed $user): bool => $user instanceof User && $user->is_active && $user->isTenantAdmin());
    }

    /**
     * While impersonating, every ability outside the allowlist is denied for the
     * impersonated user, whatever their roles say.
     *
     * Gate::before callbacks run in registration order and the first non-null answer
     * wins, so the restriction is registered before spatie/laravel-permission's own
     * permission check (whose automatic registration is disabled in config/permission.php
     * for this reason) and would otherwise grant permission-named abilities first.
     */
    protected function restrictAbilities(): void
    {
        Gate::before(function (mixed $user, string $ability): ?bool {
            $active = ActiveImpersonation::current();

            if ($active === null || ! $user instanceof User || $user->getKey() !== $active->targetUserId()) {
                return null;
            }

            return RestrictionPolicy::allowsAbility($ability) ? null : false;
        });

        // The same steps as the package's provider, in the order it would take them.
        $permissionRegistrar = $this->app->make(PermissionRegistrar::class);
        $permissionRegistrar->clearPermissionsCollection();
        $permissionRegistrar->registerPermissions($this->app->make(GateContract::class));
    }

    /**
     * Livewire updates reach components through a single endpoint, so methods and
     * property updates are checked per component before they run.
     */
    protected function restrictLivewire(): void
    {
        Livewire::listen('call', function (Component $component, string $method): void {
            $active = ActiveImpersonation::current();

            if ($active === null || RestrictionPolicy::allowsLivewireCall($method)) {
                return;
            }

            $active->recordDenied($component->getName().'@'.$method, 'POST');

            throw new AuthorizationException(__('This action is not available during an impersonation session.'));
        });

        Livewire::listen('update', function (Component $component, string $path): void {
            $active = ActiveImpersonation::current();

            if ($active === null || RestrictionPolicy::allowsLivewireUpdate($component->getName())) {
                return;
            }

            $active->recordDenied($component->getName().'#'.strtok($path, '.'), 'POST');

            throw new AuthorizationException(__('This action is not available during an impersonation session.'));
        });
    }
}
