<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Dokploy/Traefik terminates TLS and proxies plain HTTP to the app,
        // so Laravel must trust the proxy to see the request as HTTPS —
        // otherwise secure cookies and https:// URL generation break.
        $middleware->trustProxies(at: '*');

        // A short reference on every request: in the logs, and on error pages.
        $middleware->append(\App\Http\Middleware\AssignRequestRef::class);

        // Tells a device that was signed out remotely why, instead of an unexplained login screen.
        $middleware->web(append: [\App\Http\Middleware\ShowRevokedSessionNotice::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
