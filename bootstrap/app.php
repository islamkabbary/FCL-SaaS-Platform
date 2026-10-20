<?php

use App\Http\Middleware\EnforceImpersonation;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureTenantHasActiveSubscription;
use App\Http\Middleware\EnsureTenantIsReady;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\InitializeTenancyByStrictSubdomain;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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
            InitializeTenancyByStrictSubdomain::class,
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

        /*
         * Outside local and tests, refuse any Host other than a central domain or a single
         * label directly under it, before routing. Links built from the request host (password
         * reset e-mails) can then never point to another domain, even if a proxy forwards an
         * arbitrary Host. Tenant identification applies the same rule on its own.
         */
        $middleware->trustHosts(at: fn (): array => collect(config()->array('tenancy.central_domains'))
            ->flatMap(fn (string $domain): array => [
                '^'.preg_quote($domain).'$',
                '^[a-z0-9]+(?:-[a-z0-9]+)*\.'.preg_quote($domain).'$',
            ])
            ->all(), subdomains: false);

        // Baseline security headers on every web response (central and tenant).
        $middleware->web(append: SecurityHeaders::class);

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
