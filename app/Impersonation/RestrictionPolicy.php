<?php

namespace App\Impersonation;

use Illuminate\Http\Request;

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

    public static function allowsAbility(string $ability): bool
    {
        return in_array($ability, self::AllowedAbilities, true);
    }

    public static function allowsRequest(Request $request): bool
    {
        if ($request->isMethodSafe()) {
            return true;
        }

        $route = $request->route();

        return $route !== null && in_array($route->getName(), self::AllowedRoutes, true);
    }

    public static function allowsLivewireCall(string $method): bool
    {
        return in_array($method, self::AllowedLivewireMethods, true);
    }

    public static function allowsLivewireUpdate(string $component): bool
    {
        return in_array($component, self::UpdatableComponents, true);
    }
}
