<?php

namespace App\Services\Spotify;

use App\Models\Room;
use Illuminate\Support\Facades\Cache;

/**
 * What the background watcher (spotify:watch) and the screens tell each other, kept in
 * the cache: whether the watcher is alive, which rooms someone is looking at, what a
 * just-issued command expects Spotify to show next, and the queue and device lists the
 * watcher last read, so a page render never has to wait for Spotify.
 */
class WatchState
{
    /** The watcher refreshes this every loop; older than this means it is not running. */
    public const HEARTBEAT_TTL = 15;

    /** A screen that polled this recently counts as someone looking at the room. */
    public const WATCHING_SECONDS = 30;

    /** How long after the last look the room is still kept reasonably fresh (slower polling). */
    public const RECENT_SECONDS = 300;

    public const BURST_SECONDS = 3;

    public static function beat(): void
    {
        Cache::put('watcher:heartbeat', now()->getTimestamp(), self::HEARTBEAT_TTL);
    }

    public static function watcherAlive(): bool
    {
        return Cache::get('watcher:heartbeat') !== null;
    }

    /** A screen (dashboard, guest page, party screen) saying "someone is looking at this room". */
    public static function touch(Room $room): void
    {
        Cache::put("watching:{$room->id}", now()->getTimestamp(), self::RECENT_SECONDS);
    }

    public static function secondsSinceTouched(Room $room): ?int
    {
        $at = Cache::get("watching:{$room->id}");

        return $at === null ? null : max(0, now()->getTimestamp() - $at);
    }

    /**
     * A command was just sent; the watcher polls fast until Spotify shows its effect.
     *
     * @param  string  $kind  skip | previous | play | pause | other
     */
    public static function expect(Room $room, string $kind, ?string $fromTrackId = null): void
    {
        Cache::put("burst:{$room->id}", [
            'kind' => $kind,
            'from_track' => $fromTrackId,
            'at' => now()->getPreciseTimestamp(3) / 1000,
            'until' => now()->getTimestamp() + self::BURST_SECONDS,
        ], self::BURST_SECONDS + 5);
    }

    /** @return array{kind: string, from_track: ?string, at: float, until: int}|null */
    public static function burst(Room $room): ?array
    {
        return Cache::get("burst:{$room->id}");
    }

    public static function clearBurst(Room $room): void
    {
        Cache::forget("burst:{$room->id}");
    }

    public static function putQueue(Room $room, array $queue): void
    {
        Cache::put("watch:queue:{$room->id}", $queue, 120);
    }

    public static function queue(Room $room): ?array
    {
        return Cache::get("watch:queue:{$room->id}");
    }

    public static function putDevices(Room $room, array $devices): void
    {
        Cache::put("watch:devices:{$room->id}", $devices, 120);
    }

    public static function devices(Room $room): ?array
    {
        return Cache::get("watch:devices:{$room->id}");
    }
}
