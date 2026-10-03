<?php

namespace App\Services\Spotify;

use App\Models\SpotifyAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpotifyWebApiClient implements SpotifyClientContract
{
    /**
     * Short-lived read cache, shared per Spotify account (not per visitor).
     * Every guest's poll re-renders the dashboard, and each render used to
     * make its own blocking round trip to Spotify with the host's token, so
     * API load and render latency grew with guest count. Only display-only
     * reads are cached; playback state is deliberately not (the sync's drift
     * check compares its progress against the clock, so a stale copy would
     * make the bar jump back), and any write we make invalidates both.
     */
    private const QUEUE_TTL_SECONDS = 3;

    private const DEVICES_TTL_SECONDS = 5;

    // Reads sit inside a render a visitor is waiting on, so they give up
    // sooner than commands, which are a deliberate action worth waiting for.
    private const READ_TIMEOUT_SECONDS = 5;

    // A playlist's order changes rarely, so it is read once and kept; one that
    // couldn't be read is retried sooner, but not on every render.
    private const CONTEXT_TTL_SECONDS = 600;

    private const CONTEXT_UNREADABLE_TTL_SECONDS = 120;

    private const CONTEXT_MAX_TRACKS = 600;

    private const COMMAND_TIMEOUT_SECONDS = 10;

    public function __construct(private SpotifyAccount $account) {}

    private function cacheKey(string $name): string
    {
        return "spotify:{$this->account->id}:{$name}";
    }

    private function forgetCachedReads(): void
    {
        Cache::forget($this->cacheKey('queue'));
        Cache::forget($this->cacheKey('devices'));
    }

    public function search(string $query, int $limit = 10): array
    {
        $response = $this->get('https://api.spotify.com/v1/search', [
            'q' => $query,
            'type' => 'track',
            'limit' => $limit,
        ]);

        if (! $response || $response->failed()) {
            Log::warning('Spotify search failed', ['status' => $response?->status(), 'body' => $response?->body()]);

            return [];
        }

        $tracks = $response->json('tracks.items', []);

        return collect($tracks)->map(fn (array $track) => [
            'id' => $track['id'],
            'uri' => $track['uri'],
            'name' => $track['name'],
            'artist' => collect($track['artists'] ?? [])->pluck('name')->join(', '),
            'album_art_url' => $track['album']['images'][0]['url'] ?? null,
            'duration_ms' => $track['duration_ms'],
        ])->all();
    }

    public function getDevices(bool $allowCached = false): array
    {
        $key = $this->cacheKey('devices');

        if ($allowCached && is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $response = $this->get('https://api.spotify.com/v1/me/player/devices');

        if (! $response || $response->failed()) {
            Log::warning('Spotify device listing failed', ['status' => $response?->status(), 'body' => $response?->body()]);

            $devices = [];
        } else {
            $devices = collect($response->json('devices', []))->map(fn (array $device) => [
                'id' => $device['id'],
                'name' => $device['name'],
                'type' => $device['type'],
                'is_active' => $device['is_active'],
            ])->all();
        }

        // Written even on a fresh read (and on failure, so an outage or rate
        // limit isn't hammered once per render): later display reads reuse it.
        Cache::put($key, $devices, self::DEVICES_TTL_SECONDS);

        return $devices;
    }

    public function playTrack(string $trackUri, ?string $deviceId, int $positionMs = 0): bool
    {
        $query = $deviceId ? ['device_id' => $deviceId] : [];

        return $this->putWithQuery('https://api.spotify.com/v1/me/player/play', $query, [
            'uris' => [$trackUri],
            'position_ms' => $positionMs,
        ]);
    }

    /**
     * Plays a specific track inside a context (a playlist) at a given
     * position, instead of replacing the context with a single bare track
     * URI. A bare `uris:[trackUri]` play wipes out the context entirely:
     * once that track ends, Spotify has nothing left to advance to. `offset`
     * + `position_ms` inside a `context_uri` play jumps to that exact spot
     * while keeping the context (and its native auto-advance) intact — the
     * same thing clicking a track inside a playlist does in Spotify itself.
     */
    public function playContextAtTrack(string $contextUri, string $trackUri, int $positionMs, ?string $deviceId = null): bool
    {
        $query = $deviceId ? ['device_id' => $deviceId] : [];

        return $this->putWithQuery('https://api.spotify.com/v1/me/player/play', $query, [
            'context_uri' => $contextUri,
            'offset' => ['uri' => $trackUri],
            'position_ms' => $positionMs,
        ]);
    }

    /** Resumes exactly where a fallback-playlist track was paused, preserving its stored shuffle state. */
    public function resumeContext(string $contextUri, string $trackUri, int $positionMs, bool $shuffle, ?string $deviceId = null): bool
    {
        $query = $deviceId ? ['device_id' => $deviceId] : [];

        $this->putWithQuery('https://api.spotify.com/v1/me/player/shuffle', [
            ...$query,
            'state' => $shuffle ? 'true' : 'false',
        ], []);

        return $this->playContextAtTrack($contextUri, $trackUri, $positionMs, $deviceId);
    }

    public function pause(?string $deviceId = null): bool
    {
        $query = $deviceId ? ['device_id' => $deviceId] : [];

        return $this->putWithQuery('https://api.spotify.com/v1/me/player/pause', $query, []);
    }

    /**
     * Spotify's seek endpoint takes position_ms as a query parameter, not a
     * request body field — sending it as JSON body (the previous bug here)
     * gets silently ignored, so the seek never actually happens.
     */
    public function seek(int $positionMs, ?string $deviceId = null): bool
    {
        $query = ['position_ms' => $positionMs];

        if ($deviceId) {
            $query['device_id'] = $deviceId;
        }

        return $this->putWithQuery('https://api.spotify.com/v1/me/player/seek', $query, []);
    }

    /**
     * Same as seek() — volume_percent belongs in the query string, not the
     * request body.
     */
    public function setVolume(int $percent, ?string $deviceId = null): bool
    {
        $query = ['volume_percent' => max(0, min(100, $percent))];

        if ($deviceId) {
            $query['device_id'] = $deviceId;
        }

        return $this->putWithQuery('https://api.spotify.com/v1/me/player/volume', $query, []);
    }

    public function searchPlaylists(string $query, int $limit = 8): array
    {
        $response = $this->get('https://api.spotify.com/v1/search', [
            'q' => $query,
            'type' => 'playlist',
            'limit' => $limit,
        ]);

        if (! $response || $response->failed()) {
            Log::warning('Spotify playlist search failed', ['status' => $response?->status(), 'body' => $response?->body()]);

            return [];
        }

        return $this->mapPlaylists($response->json('playlists.items', []));
    }

    public function myPlaylists(int $limit = 50): array
    {
        $response = $this->get('https://api.spotify.com/v1/me/playlists', [
            'limit' => $limit,
        ]);

        if (! $response || $response->failed()) {
            Log::warning('Spotify playlist listing failed', ['status' => $response?->status(), 'body' => $response?->body()]);

            return [];
        }

        return $this->mapPlaylists($response->json('items', []));
    }

    public function getPlaylist(string $id): ?array
    {
        $response = $this->get("https://api.spotify.com/v1/playlists/{$id}");

        if (! $response || $response->failed()) {
            return null;
        }

        return $this->mapPlaylist($response->json());
    }

    public function playContext(string $contextUri, ?string $deviceId, bool $shuffle = true): bool
    {
        $query = $deviceId ? ['device_id' => $deviceId] : [];

        $this->putWithQuery('https://api.spotify.com/v1/me/player/shuffle', [
            ...$query,
            'state' => $shuffle ? 'true' : 'false',
        ], []);

        return $this->putWithQuery('https://api.spotify.com/v1/me/player/play', $query, [
            'context_uri' => $contextUri,
        ]);
    }

    public function addToPlaybackQueue(string $uri, ?string $deviceId = null): bool
    {
        $query = ['uri' => $uri];

        if ($deviceId) {
            $query['device_id'] = $deviceId;
        }

        return $this->postWithQuery('https://api.spotify.com/v1/me/player/queue', $query);
    }

    public function skipToNext(?string $deviceId = null): bool
    {
        return $this->postWithQuery('https://api.spotify.com/v1/me/player/next', $deviceId ? ['device_id' => $deviceId] : []);
    }

    public function skipToPrevious(?string $deviceId = null): bool
    {
        return $this->postWithQuery('https://api.spotify.com/v1/me/player/previous', $deviceId ? ['device_id' => $deviceId] : []);
    }

    public function setShuffle(bool $enabled, ?string $deviceId = null): bool
    {
        $query = ['state' => $enabled ? 'true' : 'false'];

        if ($deviceId) {
            $query['device_id'] = $deviceId;
        }

        return $this->putWithQuery('https://api.spotify.com/v1/me/player/shuffle', $query, []);
    }

    public function setRepeat(string $mode, ?string $deviceId = null): bool
    {
        $query = ['state' => $mode];

        if ($deviceId) {
            $query['device_id'] = $deviceId;
        }

        return $this->putWithQuery('https://api.spotify.com/v1/me/player/repeat', $query, []);
    }

    private function postWithQuery(string $url, array $query): bool
    {
        if (! empty($query)) {
            $url .= '?'.http_build_query($query);
        }

        app(CommandFailure::class)->clear();
        $response = $this->send('post', $url);
        $this->forgetCachedReads();

        if (! $response || $response->failed()) {
            app(CommandFailure::class)->record($response?->status(), $response?->body());
            Log::warning('Spotify playback command failed', ['url' => $url, 'status' => $response?->status(), 'body' => $response?->body()]);
        }

        return (bool) $response?->successful();
    }

    public function getQueue(): array
    {
        $key = $this->cacheKey('queue');

        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $response = $this->get('https://api.spotify.com/v1/me/player/queue');

        if (! $response || $response->failed()) {
            Log::warning('Spotify queue fetch failed', ['status' => $response?->status(), 'body' => $response?->body()]);

            Cache::put($key, [], self::QUEUE_TTL_SECONDS);

            return [];
        }

        $queue = collect($response->json('queue', []))
            ->filter()
            ->filter(fn (array $track) => ($track['type'] ?? 'track') === 'track')
            ->map(fn (array $track) => [
                'id' => $track['id'] ?? null,
                'uri' => $track['uri'] ?? null,
                'name' => $track['name'] ?? 'Unknown track',
                'artist' => collect($track['artists'] ?? [])->pluck('name')->join(', '),
                'album_art_url' => $track['album']['images'][0]['url'] ?? null,
                'duration_ms' => (int) ($track['duration_ms'] ?? 0),
            ])
            ->values()
            ->all();

        Cache::put($key, $queue, self::QUEUE_TTL_SECONDS);

        return $queue;
    }

    public function getContextTrackIds(string $contextUri): ?array
    {
        if (! preg_match('/^spotify:(playlist|album):([A-Za-z0-9]+)$/', $contextUri, $m)) {
            return null;
        }

        [, $type, $id] = $m;
        $key = $this->cacheKey("context:{$contextUri}");
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached ?: null;
        }

        // Spotify renamed the playlist endpoint in Feb 2026; try the new path, then the old one.
        $paths = $type === 'playlist' ? ["/playlists/{$id}/items", "/playlists/{$id}/tracks"] : ["/albums/{$id}/tracks"];

        foreach ($paths as $path) {
            $ids = $this->readContextPath('https://api.spotify.com/v1'.$path, $type);

            if ($ids) {
                Cache::put($key, $ids, self::CONTEXT_TTL_SECONDS);

                return $ids;
            }
        }

        // An empty array is the "couldn't read it" marker, so it isn't retried every render.
        Cache::put($key, [], self::CONTEXT_UNREADABLE_TTL_SECONDS);

        return null;
    }

    /** @return array<int, string> */
    private function readContextPath(string $url, string $type): array
    {
        $ids = [];
        $offset = 0;

        do {
            $response = $this->get($url, ['limit' => 50, 'offset' => $offset, 'market' => 'from_token']);

            // A half-read list would give a wrong remainder, so it counts as unreadable.
            if (! $response || $response->failed()) {
                return [];
            }

            foreach ($response->json('items', []) as $row) {
                // Playlists: items[].item (new) or items[].track (old). Albums: items[] are the tracks.
                $track = $type === 'album' ? $row : ($row['item'] ?? $row['track'] ?? null);

                if (is_array($track) && ! empty($track['id'])) {
                    $ids[] = $track['id'];
                }
            }

            $offset += 50;
        } while ($response->json('next') && count($ids) < self::CONTEXT_MAX_TRACKS);

        // Too long to read in full: the remainder can't be counted from it either.
        return $response->json('next') ? [] : $ids;
    }

    public function getPlaybackState(): ?array
    {
        $response = $this->get('https://api.spotify.com/v1/me/player');

        if (! $response) {
            throw new SpotifyRequestFailed('Spotify playback state could not be fetched');
        }

        if ($response->status() === 204) {
            return null;
        }

        if (! $response || $response->failed()) {
            Log::warning('Spotify playback state fetch failed', ['status' => $response?->status(), 'body' => $response?->body()]);

            throw new SpotifyRequestFailed('Spotify playback state fetch failed with status '.$response->status());
        }

        $json = $response->json();
        $item = $json['item'] ?? null;

        if (! $json || ! $item) {
            return null;
        }

        return [
            'is_playing' => (bool) ($json['is_playing'] ?? false),
            'progress_ms' => (int) ($json['progress_ms'] ?? 0),
            'track_id' => $item['id'] ?? null,
            'device_id' => $json['device']['id'] ?? null,
            'name' => $item['name'] ?? null,
            'artist' => collect($item['artists'] ?? [])->pluck('name')->join(', ') ?: null,
            'album_art_url' => $item['album']['images'][0]['url'] ?? null,
            'duration_ms' => (int) ($item['duration_ms'] ?? 0),
            'context_uri' => $json['context']['uri'] ?? null,
            'shuffle_enabled' => (bool) ($json['shuffle_state'] ?? false),
            // Not in Spotify's docs, but present in the player state (true only alongside shuffle_state).
            'smart_shuffle_enabled' => (bool) ($json['smart_shuffle'] ?? false),
            // False when the device can't be volume-controlled (some phones and speakers); its volume number then means nothing.
            'supports_volume' => (bool) ($json['device']['supports_volume'] ?? true),
            'volume_percent' => ($json['device']['supports_volume'] ?? true) && isset($json['device']['volume_percent'])
                ? (int) $json['device']['volume_percent']
                : null,
            'repeat_mode' => $json['repeat_state'] ?? 'off',
        ];
    }

    /**
     * @return array<int, array{id: string, uri: string, name: string, owner: ?string, image_url: ?string, track_count: ?int}>
     */
    private function mapPlaylists(array $playlists): array
    {
        return collect($playlists)->filter()->map(fn (array $playlist) => $this->mapPlaylist($playlist))->values()->all();
    }

    /**
     * @return array{id: string, uri: string, name: string, owner: ?string, image_url: ?string, track_count: ?int}
     */
    private function mapPlaylist(array $playlist): array
    {
        return [
            'id' => $playlist['id'],
            'uri' => $playlist['uri'],
            'name' => $playlist['name'],
            'owner' => $playlist['owner']['display_name'] ?? null,
            'image_url' => $playlist['images'][0]['url'] ?? null,
            // Spotify's search and "my playlists" endpoints frequently omit
            // an accurate total (only the full single-playlist fetch always
            // has it) — null here means "unknown", not "empty".
            'track_count' => $playlist['tracks']['total'] ?? null,
        ];
    }

    private function putWithQuery(string $url, array $query, array $body): bool
    {
        if (! empty($query)) {
            $url .= '?'.http_build_query($query);
        }

        app(CommandFailure::class)->clear();
        $response = $this->send('put', $url, $body);
        $this->forgetCachedReads();

        if (! $response || $response->failed()) {
            app(CommandFailure::class)->record($response?->status(), $response?->body());
            Log::warning('Spotify playback command failed', [
                'url' => $url,
                'status' => $response?->status(),
                'body' => $response?->body(),
            ]);
        }

        return (bool) $response?->successful();
    }

    private function get(string $url, array $query = []): ?Response
    {
        return $this->attempt(fn () => $this->http(self::READ_TIMEOUT_SECONDS)->get($url, $query));
    }

    private function send(string $method, string $url, array $body = []): ?Response
    {
        return $this->attempt(function () use ($method, $url, $body) {
            $request = $this->http(self::COMMAND_TIMEOUT_SECONDS)->asJson();

            return $method === 'put' ? $request->put($url, $body) : $request->post($url);
        });
    }

    /**
     * Null means "no usable answer": the request couldn't be made at all
     * (timeout, DNS, connection refused, which Laravel throws instead of
     * returning a failed response) or this account is flagged as needing a
     * reconnect. Callers handle that the same way as an error response.
     */
    private function attempt(callable $call): ?Response
    {
        try {
            return $call();
        } catch (ConnectionException|SpotifyRequestFailed $e) {
            Log::warning('Spotify request not made or not answered', ['reason' => $e->getMessage()]);

            return null;
        }
    }

    private function http(int $timeoutSeconds): PendingRequest
    {
        app(SpotifyTokenManager::class)->ensureFreshToken($this->account);

        if ($this->account->needsReconnect()) {
            throw new SpotifyRequestFailed('Spotify connection needs to be reconnected');
        }

        return Http::withToken($this->account->access_token)
            ->acceptJson()
            ->timeout($timeoutSeconds);
    }
}
