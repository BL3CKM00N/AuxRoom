<?php

namespace App\Services\Spotify;

use App\Events\RoomUpdated;
use App\Models\Room;
use App\Services\Queue\QueueFeeder;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
 *  - someone is looking (a screen polled in the last 30 s) and music is playing: every 2 seconds, and every
 *    0.3 s around the end of a song (the position itself is counted from the clock, so a read only has to notice
 *    what was changed in the Spotify app);
 *  - looked at, but nothing is playing: every 4 seconds;
 *  - looked at in the last 5 minutes: every 10 seconds;
 *  - otherwise the room is left alone.
 * Failures back off for as long as Spotify asked (rate limits) or exponentially.
 */
class RoomWatcher
{
    public const BURST_INTERVAL = 0.3;

    /**
     * Spotify gives each app a quota for its playback endpoints, and when it runs out it blocks them for hours (see
     * SpotifyBudget). One room read every second is 86,400 requests a day for as long as a screen is open, so
     * these are as slow as the accuracy allows: positions come from the clock, and the moments that matter
     * (a command, the end of a song) are read fast anyway.
     */
    public const WATCHED_INTERVAL = 2.0;

    public const IDLE_WATCHED_INTERVAL = 4.0;

    /** Nobody has done anything for 15 minutes to an hour. */
    public const QUIET_PLAYING_INTERVAL = 10.0;

    public const QUIET_PAUSED_INTERVAL = 30.0;

    /** Nobody has done anything for an hour or more: the room only needs to notice that the music started or stopped. */
    public const SLEEPING_PLAYING_INTERVAL = 30.0;

    public const SLEEPING_PAUSED_INTERVAL = 60.0;

    public const RECENT_INTERVAL = 10.0;

    /** Spotify trails its own audio by a moment; an 'other' command is given this long before its read is trusted. */
    private const SETTLE_SECONDS = 0.8;

    /** Around a song's end the room is read at the fast interval: from this long before the end... */
    private const END_WINDOW_BEFORE_MS = 1500;

    /** ...until this long after it. */
    private const END_WINDOW_AFTER_MS = 2000;

    private const QUEUE_REFRESH_SECONDS = 10;

    private const DEVICES_REFRESH_SECONDS = 30;

    public function __construct(private PlaybackSync $sync, private SpotifyClientFactory $clients, private QueueFeeder $feeder) {}

    /** Seconds until this room should be polled again, or null when nobody needs it. */
    public function cadence(Room $room): ?float
    {
        if (WatchState::burst($room)) {
            return self::BURST_INTERVAL;
        }

        $since = WatchState::secondsSinceTouched($room);

        $base = match (true) {
            $since === null => null,
            $since <= WatchState::WATCHING_SECONDS => $this->pace($room),
            default => max(self::RECENT_INTERVAL, $this->pace($room)),
        };

        return $base === null ? null : $this->aroundTrackEnd($room, $base);
    }

    /**
     * How often a room somebody has open is read. Mostly it is about what people are doing: while they press
     * things, accuracy matters and it is read every couple of seconds; when nobody has done anything for a
     * while the music just plays, positions come from the clock, and only a change made in the Spotify app
     * needs noticing, which a slower read does fine. Around the end of a song it is read fast either way.
     */
    private function pace(Room $room): float
    {
        return match ($room->activityTier()) {
            'active' => $room->is_playing ? self::WATCHED_INTERVAL : self::IDLE_WATCHED_INTERVAL,
            'quiet' => $room->is_playing ? self::QUIET_PLAYING_INTERVAL : self::QUIET_PAUSED_INTERVAL,
            default => $room->is_playing ? self::SLEEPING_PLAYING_INTERVAL : self::SLEEPING_PAUSED_INTERVAL,
        };
    }

    /** Is this room due for a look? A command, or a person waking it, cannot be made to wait for the schedule. */
    public function isDue(Room $room, ?float $dueAt): bool
    {
        $woken = WatchState::takeWake($room);

        return $dueAt === null || microtime(true) >= $dueAt || $woken || WatchState::burst($room) !== null;
    }

    /**
     * The next song starts at a moment we can calculate, and that is the change everyone is looking at. From
     * 1.5 seconds before the end until 2 seconds after (Spotify moves on a little late) the room is read every
     * 0.3 seconds, and a slow cadence is shortened so its next read lands when that window opens.
     */
    private function aroundTrackEnd(Room $room, float $base): float
    {
        if (! $room->is_playing || $room->now_playing_duration_ms <= 0 || $room->repeat_mode === 'track') {
            return $base;
        }

        $remainingMs = $room->now_playing_duration_ms - $room->currentPositionMs();

        // Well past the end the room is stale or repeating: nothing to wait for.
        if ($remainingMs < -self::END_WINDOW_AFTER_MS) {
            return $base;
        }

        // Inside the window this is below the burst interval, so it is the burst interval; before it, the time until it opens.
        return max(self::BURST_INTERVAL, min($base, ($remainingMs - self::END_WINDOW_BEFORE_MS) / 1000));
    }

    /**
     * Sends the playback-state requests of several rooms at once. The answers go to tick(), which parses them
     * and handles failures exactly as for a single request. Only worth it for two or more.
     *
     * @param  Collection<int, Room>  $rooms
     * @return array<int, array{response: Response, at: float}>
     */
    public function prefetch(Collection $rooms): array
    {
        $clients = [];

        foreach ($rooms as $room) {
            $client = $this->clients->forRoom($room);

            if ($client instanceof SpotifyWebApiClient) {
                $clients[$room->id] = $client;
            }
        }

        if (count($clients) < 2) {
            return [];
        }

        try {
            $startedAt = microtime(true);
            $responses = Http::pool(function (Pool $pool) use ($clients) {
                foreach ($clients as $id => $client) {
                    try {
                        $client->addPlaybackStateRequest($pool, (string) $id);
                    } catch (SpotifyRequestFailed) {
                        // An account that needs reconnecting is left to its own tick, which knows what to do about it.
                    }
                }
            });
            $finishedAt = microtime(true);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $answers = [];

        foreach ($clients as $id => $client) {
            $response = $responses[(string) $id] ?? null;

            if ($response instanceof Response) {
                $took = $response->transferStats?->getTransferTime() ?? ($finishedAt - $startedAt);
                $answers[$id] = ['response' => $response, 'at' => ($startedAt + $took / 2) * 1000];
            }
        }

        return $answers;
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
    public function tick(Room $room, ?array $prefetched = null): ?float
    {
        $room = Room::with('playbackProvider.spotifyAccount')->find($room->id);

        if (! $room || ! $this->watchable($room)) {
            return null;
        }

        $client = $this->clients->forRoom($room);

        try {
            $state = $prefetched && $client instanceof SpotifyWebApiClient
                ? $client->playbackStateFrom($prefetched['response'], $prefetched['at'])
                : $client->getPlaybackState();
        } catch (SpotifyRequestFailed $e) {
            return $this->backoff($room, $e);
        }

        Cache::forget("watch:failures:{$room->id}");
        WatchState::markVerified($room);

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
        $this->afterSync($room);
        $this->refreshLists($room, $client, $state);

        $next = $this->cadence($room);

        return $next === null ? null : max(self::BURST_INTERVAL, $next);
    }

    /** The waiting list's part of a tick (see QueueFeeder::afterSync). A failure there never stops the watcher. */
    private function afterSync(Room $room): void
    {
        try {
            $this->feeder->afterSync($room);
        } catch (\Throwable $e) {
            report($e);
        }
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
                $before = WatchState::queue($room);
                $queue = $client->getQueue(fresh: true);
                WatchState::putQueue($room, $queue);
                Cache::put("watch:queue:fresh:{$room->id}", true, self::QUEUE_REFRESH_SECONDS);
                Cache::put($trackKey, $state['track_id'] ?? null, 3600);

                // The queue moved on (the song that just started left it) or somebody changed it in the Spotify app.
                // Screens re-rendered for the track change a moment ago, possibly before this was stored.
                if ($before !== null && array_column($before, 'id') !== array_column($queue, 'id')) {
                    RoomUpdated::broadcastFor($room, 'queue');
                }
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
