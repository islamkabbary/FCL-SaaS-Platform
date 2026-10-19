<?php

declare(strict_types=1);

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
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Tenant administration. The "can" middleware is re-applied to Livewire updates,
    // and every action authorizes again in the backend.
    Route::livewire('employees', 'pages::employees.index')
        ->middleware('can:viewAny,'.User::class)
        ->name('employees.index');

    Route::livewire('roles', 'pages::roles.index')
        ->middleware('can:viewAny,'.Role::class)
        ->name('roles.index');
});

require __DIR__.'/settings.php';
