<?php

use App\Http\Controllers\Platform\AuthenticatedSessionController;
use App\Http\Controllers\Platform\TenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central Routes
|--------------------------------------------------------------------------
|
| Routes of the central platform application. They are bound to the central
| domain so they are never reachable from a tenant subdomain. Tenant routes
| live in routes/tenant.php.
|
*/

Route::domain(config('tenancy.central_domains')[0])->group(function () {
    Route::view('/', 'welcome')->name('central.home');

    Route::prefix('admin')->name('platform.')->group(function () {
        Route::middleware('guest:platform')->group(function () {
            Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
            Route::post('login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
        });

        Route::middleware('auth:platform')->group(function () {
            Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

            Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
            Route::get('tenants/create', [TenantController::class, 'create'])->name('tenants.create');
            Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
        });
    });
});
