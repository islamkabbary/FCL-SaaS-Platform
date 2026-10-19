<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

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
});

require __DIR__.'/settings.php';
