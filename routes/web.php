<?php

use App\Http\Controllers\Platform\AuthenticatedSessionController;
use App\Http\Controllers\Platform\DashboardController;
use App\Http\Controllers\Platform\TenantAccessController;
use App\Http\Controllers\Platform\TenantController;
use App\Models\Tenant;
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

            Route::get('/', DashboardController::class)->name('dashboard');

            // "auth:platform" makes the platform guard the default, so "can" checks the Platform Admin.
            Route::get('tenants', [TenantController::class, 'index'])->middleware('can:viewAny,'.Tenant::class)->name('tenants.index');
            Route::get('tenants/create', [TenantController::class, 'create'])->middleware('can:create,'.Tenant::class)->name('tenants.create');
            Route::post('tenants', [TenantController::class, 'store'])->middleware('can:create,'.Tenant::class)->name('tenants.store');

            Route::middleware('can:view,tenant')->group(function () {
                Route::get('tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
                Route::get('tenants/{tenant}/history', [TenantController::class, 'history'])->name('tenants.history');
            });

            Route::middleware('can:update,tenant')->group(function () {
                Route::get('tenants/{tenant}/edit', [TenantController::class, 'edit'])->name('tenants.edit');
                Route::put('tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
            });

            Route::middleware('can:suspend,tenant')->group(function () {
                Route::get('tenants/{tenant}/suspend', [TenantAccessController::class, 'confirmSuspension'])->name('tenants.suspend');
                Route::post('tenants/{tenant}/suspend', [TenantAccessController::class, 'suspend'])->name('tenants.suspend.store');
            });

            Route::middleware('can:reactivate,tenant')->group(function () {
                Route::get('tenants/{tenant}/reactivate', [TenantAccessController::class, 'confirmReactivation'])->name('tenants.reactivate');
                Route::post('tenants/{tenant}/reactivate', [TenantAccessController::class, 'reactivate'])->name('tenants.reactivate.store');
            });
        });
    });
});
