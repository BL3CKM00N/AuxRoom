<?php

namespace App\Services\Spotify;

use App\Models\Room;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keeps one room's stored state true to Spotify without any browser asking: the
 * server polls Spotify itself, so screens only ever display what the server knows
 * and the room is accurate even with every tab closed.
 *
 * How often depends on who is looking, and on whether a command was just sent:
 *  - right after a command (skip, pause, ...): every 0.3 s for up to 3 s, until
 *    Spotify shows the command's effect. A read that still shows the old state is
 *    ignored (Spotify lags a moment behind its own audio), instead of the old
 *    blind spot of ignoring everything for 4 seconds;
 *  - someone is looking (a screen polled in the last 30 s): every second;
 *  - looked at in the last 5 minutes: every 5 seconds;
 *  - otherwise the room is left alone.
 * Failures back off for as long as Spotify asked (rate limits) or exponentially.
 */
class RoomWatcher
{
    public const BURST_INTERVAL = 0.3;

    public const WATCHED_INTERVAL = 1.0;

    public const RECENT_INTERVAL = 5.0;

    /** Spotify trails its own audio by a moment; an 'other' command is given this long before its read is trusted. */
    private const SETTLE_SECONDS = 0.8;

    private const QUEUE_REFRESH_SECONDS = 5;

    private const DEVICES_REFRESH_SECONDS = 10;

    public function __construct(private PlaybackSync $sync, private SpotifyClientFactory $clients) {}

    /** Seconds until this room should be polled again, or null when nobody needs it. */
    public function cadence(Room $room): ?float
    {
        if (WatchState::burst($room)) {
            return self::BURST_INTERVAL;
        }

        $since = WatchState::secondsSinceTouched($room);

        return match (true) {
            $since === null => null,
            $since <= WatchState::WATCHING_SECONDS => self::WATCHED_INTERVAL,
            default => self::RECENT_INTERVAL,
        };
    }

    /** Is this a room the watcher can look at (a real Spotify, still connected)? */
    public function watchable(Room $room): bool
    {
        return $room->closed_at === null
            && ! $this->clients->isMock($room)
            && $room->playbackProvider?->hasSpotifyConnected()
            && ! $room->playbackProvider->spotifyAccount->needsReconnect();
    }

    /**
     * One poll of one room. Returns the seconds to wait before the next one (never below
     * the burst interval), or null when the room no longer needs watching.
     */
    public function tick(Room $room): ?float
    {
        $room = Room::with('playbackProvider.spotifyAccount')->find($room->id);

        if (! $room || ! $this->watchable($room)) {
            return null;
        }

        $client = $this->clients->forRoom($room);

        try {
            $state = $client->getPlaybackState();
        } catch (SpotifyRequestFailed $e) {
            return $this->backoff($room, $e);
        }

        Cache::forget("watch:failures:{$room->id}");

        $burst = WatchState::burst($room);

        if ($burst && ! $this->settled($state, $burst)) {
            return self::BURST_INTERVAL;
        }

        if ($burst) {
            WatchState::clearBurst($room);
            Log::info('Playback confirmed', [
                'room' => $room->id,
                'command' => $burst['kind'],
                'ms_after_command' => (int) round((microtime(true) - $burst['at']) * 1000),
            ]);
        }

        $this->sync->applyState($room, $state);
        $this->refreshLists($room, $client, $state);

        $next = $this->cadence($room);

        return $next === null ? null : max(self::BURST_INTERVAL, $next);
    }

    /**
     * Has Spotify's answer caught up with the command that was sent? An answer that still shows
     * the old state is the read-after-write lag, not the truth, and is not applied yet. When the
     * burst window runs out, whatever Spotify says is taken as the truth.
     *
     * @param  array{kind: string, from_track: ?string, at: float, until: int}  $burst
     */
    private function settled(?array $state, array $burst): bool
    {
        if (now()->getTimestamp() >= $burst['until']) {
            return true;
        }

        return match ($burst['kind']) {
            'skip', 'previous' => $burst['from_track'] === null || $state === null || $state['track_id'] !== $burst['from_track'],
            'pause' => $state === null || ! $state['is_playing'],
            'play' => $state !== null && $state['is_playing'],
            default => (microtime(true) - $burst['at']) >= self::SETTLE_SECONDS,
        };
    }

    /** Queue and device lists, kept in the cache so a page render never waits on Spotify. */
    private function refreshLists(Room $room, SpotifyClientContract $client, ?array $state): void
    {
        $trackKey = "watch:queue:track:{$room->id}";
        $trackChanged = Cache::get($trackKey) !== ($state['track_id'] ?? null);
        $queueStale = ! Cache::has("watch:queue:fresh:{$room->id}");

        if ($trackChanged || $queueStale) {
            try {
                WatchState::putQueue($room, $client->getQueue(fresh: true));
                Cache::put("watch:queue:fresh:{$room->id}", true, self::QUEUE_REFRESH_SECONDS);
                Cache::put($trackKey, $state['track_id'] ?? null, 3600);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // The playlist's real order, so "coming up" can be trimmed without a request at render time.
        if (Room::isPlayableContext($state['context_uri'] ?? null)) {
            $client->getContextTrackIds($state['context_uri']);
        }

        if (! Cache::has("watch:devices:fresh:{$room->id}")) {
            WatchState::putDevices($room, $client->getDevices());
            Cache::put("watch:devices:fresh:{$room->id}", true, self::DEVICES_REFRESH_SECONDS);
        }
    }

    private function backoff(Room $room, SpotifyRequestFailed $e): float
    {
        $failures = (int) Cache::increment("watch:failures:{$room->id}");
        Cache::put("watch:failures:{$room->id}", $failures, 300);

        $wait = max((float) ($e->retryAfter ?? 0), min(30.0, 2 ** min($failures, 5)));

        Log::warning('Spotify watcher backing off', ['room' => $room->id, 'status' => $e->status(), 'failures' => $failures, 'wait_seconds' => $wait]);

        return $wait;
    }
}
