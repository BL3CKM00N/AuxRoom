<?php

namespace App\Services\Spotify;

use App\Models\SpotifyAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpotifyWebApiClient implements SpotifyClientContract
{
    public function __construct(private SpotifyAccount $account) {}

    public function search(string $query, int $limit = 10): array
    {
        $response = $this->http()->get('https://api.spotify.com/v1/search', [
            'q' => $query,
            'type' => 'track',
            'limit' => $limit,
        ]);

        if ($response->failed()) {
            Log::warning('Spotify search failed', ['status' => $response->status(), 'body' => $response->body()]);

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

    public function getDevices(): array
    {
        $response = $this->http()->get('https://api.spotify.com/v1/me/player/devices');

        if ($response->failed()) {
            Log::warning('Spotify device listing failed', ['status' => $response->status(), 'body' => $response->body()]);

            return [];
        }

        return collect($response->json('devices', []))->map(fn (array $device) => [
            'id' => $device['id'],
            'name' => $device['name'],
            'type' => $device['type'],
            'is_active' => $device['is_active'],
        ])->all();
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
        $response = $this->http()->get('https://api.spotify.com/v1/search', [
            'q' => $query,
            'type' => 'playlist',
            'limit' => $limit,
        ]);

        if ($response->failed()) {
            Log::warning('Spotify playlist search failed', ['status' => $response->status(), 'body' => $response->body()]);

            return [];
        }

        return $this->mapPlaylists($response->json('playlists.items', []));
    }

    public function myPlaylists(int $limit = 50): array
    {
        $response = $this->http()->get('https://api.spotify.com/v1/me/playlists', [
            'limit' => $limit,
        ]);

        if ($response->failed()) {
            Log::warning('Spotify playlist listing failed', ['status' => $response->status(), 'body' => $response->body()]);

            return [];
        }

        return $this->mapPlaylists($response->json('items', []));
    }

    public function getPlaylist(string $id): ?array
    {
        $response = $this->http()->get("https://api.spotify.com/v1/playlists/{$id}");

        if ($response->failed()) {
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

        $response = $this->http()->post($url);

        if ($response->failed()) {
            Log::warning('Spotify playback command failed', ['url' => $url, 'status' => $response->status(), 'body' => $response->body()]);
        }

        return $response->successful();
    }

    public function getQueue(): array
    {
        $response = $this->http()->get('https://api.spotify.com/v1/me/player/queue');

        if ($response->failed()) {
            Log::warning('Spotify queue fetch failed', ['status' => $response->status(), 'body' => $response->body()]);

            return [];
        }

        return collect($response->json('queue', []))
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
    }

    public function getPlaybackState(): ?array
    {
        $response = $this->http()->get('https://api.spotify.com/v1/me/player');

        if ($response->status() === 204) {
            return null;
        }

        if ($response->failed()) {
            Log::warning('Spotify playback state fetch failed', ['status' => $response->status(), 'body' => $response->body()]);

            return null;
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
        $request = $this->http()->asJson();

        if (! empty($query)) {
            $url .= '?'.http_build_query($query);
        }

        $response = $request->put($url, $body);

        if ($response->failed()) {
            Log::warning('Spotify playback command failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return $response->successful();
    }

    private function http(): PendingRequest
    {
        app(SpotifyTokenManager::class)->ensureFreshToken($this->account);

        return Http::withToken($this->account->access_token)
            ->acceptJson()
            ->timeout(10);
    }
}
