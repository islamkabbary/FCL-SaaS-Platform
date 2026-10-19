<?php

declare(strict_types=1);

use App\Enums\TenantPermission;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Routes of a tenant company, served on "{subdomain}.{central domain}". They are
| loaded from bootstrap/app.php inside the "web" and "tenant" middleware groups,
| so every request here runs against the identified tenant's own database.
| Fortify's authentication routes use the same middleware (config/fortify.php).
|
*/

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified', 'password.changed'])->group(function () {
    // Reachable without an active subscription, so users can see why access is limited
    // and a Tenant Admin can review the subscription and billing history.
    Route::view('subscription-required', 'subscription-required')->name('subscription.required');

    Route::livewire('subscription', 'pages::subscription.index')
        ->middleware('can:'.TenantPermission::BillingView->value)
        ->name('subscription.show');

    // Ordinary tenant functionality requires an active subscription (or a legacy tenant).
    Route::middleware('subscription.active')->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');

        // Tenant administration. The "can" and "subscription.active" middleware are
        // re-applied to Livewire updates, and every action authorizes again in the backend.
        Route::livewire('employees', 'pages::employees.index')
            ->middleware('can:viewAny,'.User::class)
            ->name('employees.index');

        Route::livewire('roles', 'pages::roles.index')
            ->middleware('can:viewAny,'.Role::class)
            ->name('roles.index');
    });
});

require __DIR__.'/settings.php';
