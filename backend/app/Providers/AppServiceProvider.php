<?php

namespace App\Providers;

use App\Services\CurrentUserService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton so the auth middleware's set() and every controller's
        // get() see the same instance within one request. See
        // CurrentUserService's docblock re: Octane request-scoping caveat.
        $this->app->singleton(CurrentUserService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
