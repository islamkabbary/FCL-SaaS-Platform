<?php

use App\Http\Controllers\Platform\AuthenticatedSessionController;
use App\Http\Controllers\Platform\ChargeController;
use App\Http\Controllers\Platform\DashboardController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Platform\SeatRequestController;
use App\Http\Controllers\Platform\SubscriptionController;
use App\Http\Controllers\Platform\TenantAccessController;
use App\Http\Controllers\Platform\TenantController;
use App\Http\Controllers\Platform\TwoFactorChallengeController;
use App\Http\Controllers\Platform\TwoFactorController;
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

            // Second step for admins with two-factor authentication (password already verified).
            Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
            Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:10,1')->name('two-factor.challenge.store');
        });

        Route::middleware('auth:platform')->group(function () {
            Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

            Route::get('/', DashboardController::class)->name('dashboard');

            // The signed-in admin's own two-factor authentication.
            Route::get('account/security', [TwoFactorController::class, 'show'])->name('account.security');
            Route::post('account/security/two-factor', [TwoFactorController::class, 'enable'])->name('account.two-factor.enable');
            Route::post('account/security/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('account.two-factor.confirm');
            Route::post('account/security/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('account.two-factor.recovery-codes');
            Route::delete('account/security/two-factor', [TwoFactorController::class, 'disable'])->name('account.two-factor.disable');

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

            // Subscriptions & billing (Platform Admins only; tenant users have no route here).
            Route::middleware('can:manage-billing')->group(function () {
                Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
                Route::get('plans/create', [PlanController::class, 'create'])->name('plans.create');
                Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
                Route::get('plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
                Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
                Route::post('plans/{plan}/archive', [PlanController::class, 'archive'])->name('plans.archive');
                Route::post('plans/{plan}/activate', [PlanController::class, 'activate'])->name('plans.activate');

                Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
                Route::get('tenants/{tenant}/subscription', [SubscriptionController::class, 'show'])->middleware('can:view,tenant')->name('subscriptions.show');

                Route::middleware('can:update,tenant')->group(function () {
                    Route::get('tenants/{tenant}/subscription/activate', [SubscriptionController::class, 'createActivation'])->name('subscriptions.activate');
                    Route::post('tenants/{tenant}/subscription/activate', [SubscriptionController::class, 'activate'])->name('subscriptions.activate.store');
                    Route::get('tenants/{tenant}/subscription/renew', [SubscriptionController::class, 'createRenewal'])->name('subscriptions.renew');
                    Route::post('tenants/{tenant}/subscription/renew', [SubscriptionController::class, 'renew'])->name('subscriptions.renew.store');
                });

                Route::get('seat-requests', [SeatRequestController::class, 'index'])->name('seat-requests.index');
                Route::post('seat-requests/{seatRequest}/approve', [SeatRequestController::class, 'approve'])->name('seat-requests.approve');
                Route::post('seat-requests/{seatRequest}/reject', [SeatRequestController::class, 'reject'])->name('seat-requests.reject');

                Route::get('charges', [ChargeController::class, 'index'])->name('charges.index');
                Route::get('charges/{charge}', [ChargeController::class, 'show'])->name('charges.show');
                Route::post('charges/{charge}/payments', [ChargeController::class, 'storePayment'])->name('charges.payments.store');
            });
        });
    });
});
