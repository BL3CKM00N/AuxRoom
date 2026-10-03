<?php

namespace App\Providers;

use App\Services\Spotify\CommandFailure;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One per request, so the client that records a failure and the component that reports it share it.
        $this->app->singleton(CommandFailure::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
