<?php

use App\Http\Middleware\EnforceImpersonation;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureTenantHasActiveSubscription;
use App\Http\Middleware\EnsureTenantIsReady;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use Stancl\Tenancy\Middleware\ScopeSessions;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Loaded after the central routes so the central domain's routes win on that host.
            Route::middleware(['web', 'tenant'])->group(base_path('routes/tenant.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Tenant context for a request: identify the tenant from the subdomain (this runs
         * before the session starts, so tenant sessions live in the tenant database), refuse
         * the central domain, hide tenants that are not ready, and bind the session to the
         * tenant so a session can never be replayed against another tenant. Then verify (or
         * tear down) an impersonated session, and end the session of a deactivated user.
         */
        $middleware->group('tenant', [
            InitializeTenancyBySubdomain::class,
            PreventAccessFromCentralDomains::class,
            EnsureTenantIsReady::class,
            ScopeSessions::class,
            EnforceImpersonation::class,
            EnsureUserIsActive::class,
        ]);

        /*
         * Without explicit priority, Laravel sorts "auth" ahead of these middleware. The
         * readiness check must run right after identification, and the session scope must
         * be verified before the guard reads a user id from the session; otherwise a
         * session from tenant A could resolve a user with the same id in tenant B.
         */
        $middleware->prependToPriorityList(before: EncryptCookies::class, prepend: EnsureTenantIsReady::class);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ScopeSessions::class);
        // Prepends are applied in order, so these land between ScopeSessions and auth:
        // impersonation is verified before anything else uses the authenticated user.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EnforceImpersonation::class);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EnsureUserIsActive::class);

        // The support handoff is posted from the central domain; its single-use,
        // tenant-bound credential replaces the CSRF token.
        $middleware->validateCsrfTokens(except: ['support/handoff']);

        $middleware->alias([
            'password.changed' => EnsurePasswordIsChanged::class,
            'subscription.active' => EnsureTenantHasActiveSubscription::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request): string => $request->routeIs('platform.*')
            ? route('platform.login')
            : route('login'));

        $middleware->redirectUsersTo(fn (Request $request): string => $request->routeIs('platform.*')
            ? route('platform.dashboard')
            : route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
