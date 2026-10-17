<?php

namespace App\Providers;

use App\Services\Spotify\CommandFailure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->defineRateLimits();
    }

    /**
     * Every limit has a name, and a name is its own counter. A numbered `throttle:10,1` is not: it counts per
     * visitor across all routes that use one, so the clock pings of a page load used up the join form's allowance
     * and a guest got a 429 on their first try. EchoEventNamesTest-style guard: ThrottleBucketsTest.
     */
    private function defineRateLimits(): void
    {
        $who = fn (Request $request) => $request->user()?->getAuthIdentifier() ?: $request->ip();

        // Five pings per page load, and many guests can share one address (a venue's Wi-Fi), so this is generous.
        RateLimiter::for('clock', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));

        RateLimiter::for('health', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // Chat apps fetch the invite picture, often many at once.
        RateLimiter::for('share-image', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        // Every successful submit creates a guest row. A group joining from one Wi-Fi shares one address, so it has to
        // fit a whole party arriving together, while still stopping someone from filling the table.
        RateLimiter::for('join', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('qr-login', fn (Request $request) => Limit::perMinute(30)->by($who($request)));

        RateLimiter::for('verify-email', fn (Request $request) => Limit::perMinute(6)->by($who($request)));
    }
}
