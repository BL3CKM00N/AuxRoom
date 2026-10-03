<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a short reference. It is added to every log line written
 * during the request, and shown on error pages, so "ref K3F9XQ2A" from a
 * screenshot leads straight to the exact failure in the log.
 */
class AssignRequestRef
{
    public function handle(Request $request, Closure $next): Response
    {
        $ref = strtoupper(Str::random(8));

        $request->attributes->set('ref', $ref);
        Log::shareContext(['ref' => $ref]);

        $response = $next($request);
        $response->headers->set('X-Request-Ref', $ref);

        return $response;
    }
}
