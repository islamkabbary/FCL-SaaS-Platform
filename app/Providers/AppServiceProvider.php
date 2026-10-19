<?php

namespace App\Providers;

use App\Session\TenantAwareDatabaseSessionHandler;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureSessions();
    }

    /**
     * Store sessions in the database of the current context (central or tenant).
     */
    protected function configureSessions(): void
    {
        Session::extend('database', fn (Application $app): TenantAwareDatabaseSessionHandler => new TenantAwareDatabaseSessionHandler(
            $app->make('db')->connection(config('session.connection')),
            config('session.table'),
            config('session.lifetime'),
            $app,
        ));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
