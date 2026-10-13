<?php

namespace App\Http\Controllers;

use App\Services\HealthCheck;
use Illuminate\Http\JsonResponse;

/** For uptime monitoring: 200 when the background parts are healthy, 503 when one is not. */
class HealthController extends Controller
{
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $result = $health->run();

        return response()->json($result, $result['ok'] ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
