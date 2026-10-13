<?php

namespace App\Services;

use App\Services\Spotify\WatchState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What /health/watcher reports: the things that fail quietly. The site can answer pages while the
 * background watcher is down (screens then fall back to slow polling), Reverb is down (no live
 * pushes), or the cache is broken. Nothing in here is sensitive.
 */
class HealthCheck
{
    /** @return array{ok: bool, checks: array<string, array{ok: bool, detail?: string}>} */
    public function run(): array
    {
        $checks = [
            'watcher' => $this->watcher(),
            'reverb' => $this->reverb(),
            'database' => $this->database(),
            'cache' => $this->cache(),
        ];

        return ['ok' => collect($checks)->every(fn (array $check) => $check['ok']), 'checks' => $checks];
    }

    /** @return array{ok: bool, detail?: string} */
    private function watcher(): array
    {
        $age = WatchState::heartbeatAge();

        return $age === null
            ? ['ok' => false, 'detail' => 'No heartbeat from the watcher in the last '.WatchState::HEARTBEAT_TTL.' seconds']
            : ['ok' => true, 'detail' => "Last heartbeat {$age}s ago"];
    }

    /** The websocket server runs in the same container, so it is checked on the local port. */
    private function reverb(): array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return ['ok' => true, 'detail' => 'Not in use'];
        }

        $port = (int) config('reverb.servers.reverb.port', 8080);
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.5);

        if ($socket === false) {
            return ['ok' => false, 'detail' => "Nothing is listening on the websocket port ({$port})"];
        }

        fclose($socket);

        return ['ok' => true];
    }

    private function database(): array
    {
        try {
            DB::select('select 1');

            return ['ok' => true];
        } catch (Throwable) {
            return ['ok' => false, 'detail' => 'The database did not answer'];
        }
    }

    /** A write and read on the default cache store, which is Redis with the database as a fallback. */
    private function cache(): array
    {
        try {
            $value = bin2hex(random_bytes(4));
            Cache::put('health:probe', $value, 30);

            return Cache::get('health:probe') === $value
                ? ['ok' => true]
                : ['ok' => false, 'detail' => 'The cache did not return what was stored'];
        } catch (Throwable) {
            return ['ok' => false, 'detail' => 'The cache did not answer'];
        }
    }
}
