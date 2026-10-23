<?php

namespace App\Impersonation;

use App\Http\Middleware\DenyDuringImpersonation;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * The restriction policy of every impersonated session (support access and employee
 * view). It is an allowlist: anything not listed here is refused, so functionality
 * added later (including FCL Operations) is denied while impersonating until it is
 * reviewed and explicitly added.
 *
 * Enforced in three independent layers, so hiding a button is never the protection:
 *
 * 1. HTTP: EnforceImpersonation refuses every non-GET request except the routes below.
 * 2. Livewire: every component method call and property update is checked
 *    (ImpersonationServiceProvider), before the component runs.
 * 3. Authorization: a Gate::before hook denies every ability not listed below, so the
 *    backend actions (which all authorize) refuse even if a layer above were missed.
 *
 * FCL Operations are denied entirely, reads included, in all three layers: their routes
 * (even GET), their components (on mount and on every update, before any of their own
 * code runs) and their resources (even through the generic "view" and "viewAny"
 * abilities). The same applies to anything marked DeniedDuringImpersonation.
 */
final class RestrictionPolicy
{
    /**
     * Abilities that stay available: viewing only.
     */
    public const AllowedAbilities = [
        'viewAny',
        'view',
        'employees.view',
        'roles.view',
        'billing.view',
    ];

    /**
     * Non-GET routes that stay available. Livewire updates are then checked per call.
     */
    public const AllowedRoutes = [
        'impersonation.end',
        'logout',
        'livewire.update',
    ];

    /**
     * Livewire methods that only re-render or paginate, allowed on any component.
     */
    public const AllowedLivewireMethods = [
        '$refresh',
        '$set',
        '$sync',
        '$commit',
        'gotoPage',
        'previousPage',
        'nextPage',
        'setPage',
        'resetPage',
    ];

    /**
     * The allowed pagination methods. Livewire's pagination calls updating/updated hooks
     * named after the paginator, so only the default paginator and a numeric page are
     * accepted: a client-chosen name would call any component's update hooks.
     */
    public const PaginationMethods = ['gotoPage', 'previousPage', 'nextPage', 'setPage', 'resetPage'];

    /**
     * Components whose public properties may be updated (search boxes, filters, form
     * fields). Property updates of these components have no side effects; saving still
     * requires a method, which stays refused.
     */
    public const UpdatableComponents = [
        'pages::employees.index',
        'pages::roles.index',
        'pages::subscription.index',
        'pages::settings.profile',
        'pages::settings.security',
        'pages::settings.appearance',
        'pages::settings.delete-user-modal',
    ];

    /**
     * Models in this namespace are FCL Operations data.
     */
    public const DeniedModelNamespace = 'App\\Models\\Fcl\\';

    /**
     * Route names of FCL Operations.
     */
    public const DeniedRoutePrefix = 'fcl.';

    /**
     * Component names of FCL Operations (single-file pages, and registered components).
     */
    public const DeniedComponentPrefixes = ['pages::fcl.', 'fcl.'];

    /**
     * @param  array<array-key, mixed>  $arguments  The resources the ability is checked against.
     */
    public static function allowsAbility(string $ability, array $arguments = []): bool
    {
        if (! in_array($ability, self::AllowedAbilities, true)) {
            return false;
        }

        return ! self::containsDeniedResource($arguments);
    }

    /**
     * Resources may be passed one by one or in arrays and collections, at any depth.
     *
     * @param  iterable<mixed>  $arguments
     */
    protected static function containsDeniedResource(iterable $arguments): bool
    {
        foreach ($arguments as $argument) {
            if (self::deniesResource($argument) || (is_iterable($argument) && self::containsDeniedResource($argument))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a model instance or class name may not be reached while impersonating.
     */
    public static function deniesResource(mixed $resource): bool
    {
        $class = match (true) {
            is_object($resource) => $resource::class,
            is_string($resource) => ltrim($resource, '\\'),
            default => null,
        };

        return $class !== null
            && (str_starts_with($class, self::DeniedModelNamespace) || is_a($class, DeniedDuringImpersonation::class, true));
    }

    public static function allowsRequest(Request $request): bool
    {
        $route = $request->route();

        if ($route instanceof Route && self::deniesRoute($route)) {
            return false;
        }

        if ($request->isMethodSafe()) {
            return true;
        }

        return $route !== null && in_array($route->getName(), self::AllowedRoutes, true);
    }

    /**
     * FCL routes are recognised by name and by the impersonation.deny middleware, so a
     * route that misses one of them is still refused.
     */
    public static function deniesRoute(Route $route): bool
    {
        if (str_starts_with((string) $route->getName(), self::DeniedRoutePrefix)) {
            return true;
        }

        $middleware = $route->gatherMiddleware();

        return in_array('impersonation.deny', $middleware, true)
            || in_array(DenyDuringImpersonation::class, $middleware, true);
    }

    /**
     * @param  array<array-key, mixed>  $params
     */
    public static function allowsLivewireCall(string $method, array $params = []): bool
    {
        if (! in_array($method, self::AllowedLivewireMethods, true)) {
            return false;
        }

        if (! in_array($method, self::PaginationMethods, true)) {
            return true;
        }

        $takesPage = in_array($method, ['gotoPage', 'setPage'], true);
        $params = array_values($params);

        if (count($params) > ($takesPage ? 2 : 1)) {
            return false;
        }

        if ($takesPage && ! (is_int($params[0] ?? null) || (is_string($params[0] ?? null) && ctype_digit($params[0])))) {
            return false;
        }

        return ($params[$takesPage ? 1 : 0] ?? 'page') === 'page';
    }

    public static function allowsLivewireUpdate(string $component): bool
    {
        return in_array($component, self::UpdatableComponents, true);
    }

    /**
     * Whether a component may not even be mounted, hydrated or rendered while impersonating.
     */
    public static function deniesComponent(Component $component): bool
    {
        $name = $component->getName();

        // Also a page named exactly "fcl" and an "fcl." component in any namespace.
        return $component instanceof DeniedDuringImpersonation
            || Str::startsWith($name, self::DeniedComponentPrefixes)
            || $name === 'pages::fcl'
            || str_contains($name, '::fcl.');
    }
}
