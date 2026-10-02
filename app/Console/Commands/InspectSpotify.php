<?php

namespace App\Console\Commands;

use App\Models\Room;
use App\Models\SpotifyAccount;
use App\Services\Spotify\SpotifyTokenManager;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * A read-only look at what Spotify really reports for a room, run from the
 * command line against whatever environment it's in (including production).
 *
 * It exists because Spotify's behavior (the queue padding itself by wrapping
 * around, what a dev-mode app may read, how a context reports itself) can't
 * be reproduced without a real account, and guessing at it is how the queue
 * list ended up lying. GET requests only, and credentials are never printed.
 */
class InspectSpotify extends Command
{
    protected $signature = 'spotify:inspect
        {room? : Invite code of the room to inspect (defaults to the only open room)}
        {--save= : Directory to write sanitized JSON fixtures of the real responses into}
        {--tracks=300 : Most playlist/album tracks to read when locating the current track in its context}';

    protected $description = 'Read-only: show what Spotify really reports for a room (playback, queue, devices, context order). Never prints credentials.';

    /** Keys that bloat a saved response or identify things nobody needs in a test fixture. */
    private const STRIP_KEYS = [
        'available_markets', 'external_ids', 'external_urls', 'href', 'preview_url', 'is_playable',
        'linked_from', 'restrictions', 'popularity', 'disc_number', 'is_local', 'added_by', 'primary_color',
        'video_thumbnail', 'snapshot_id', 'followers', 'owner',
    ];

    public function handle(SpotifyTokenManager $tokens): int
    {
        $room = $this->resolveRoom();

        if (! $room) {
            return self::FAILURE;
        }

        $account = $room->playbackProvider?->spotifyAccount;

        $this->summariseRoom($room, $account);

        if (! $account || ! $account->access_token) {
            $this->components->warn('No Spotify account is connected for this room, so it runs in demo mode. Nothing to inspect.');

            return self::SUCCESS;
        }

        // May refresh an expired token and store it, exactly as the app does on any request.
        $tokens->ensureFreshToken($account);

        if ($account->needsReconnect()) {
            $this->components->error('Spotify rejected this connection (needs_reconnect_at is set). Reconnect it in Room Settings, then run this again.');

            return self::FAILURE;
        }

        $saved = [];

        $player = $this->get($account, '/me/player');
        $queue = $this->get($account, '/me/player/queue');
        $devices = $this->get($account, '/me/player/devices');

        $saved['player'] = $player['json'];
        $saved['queue'] = $queue['json'];
        $saved['devices'] = $devices['json'];

        $this->showDevices($devices);
        $state = $this->showPlayback($room, $player);
        $queueIds = $this->showQueue($queue, $state);
        $contextIds = $this->showContext($account, $state, $queueIds, $saved);

        $this->conclude($state, $queueIds, $contextIds);

        if ($directory = $this->option('save')) {
            $this->save($directory, $saved);
        }

        return self::SUCCESS;
    }

    private function resolveRoom(): ?Room
    {
        if ($code = $this->argument('room')) {
            $room = Room::where('invite_code', strtoupper(trim($code)))->first();

            if (! $room) {
                $this->components->error("No room with invite code {$code}.");
            }

            return $room;
        }

        $open = Room::whereNull('closed_at')->get();

        if ($open->count() === 1) {
            return $open->first();
        }

        $this->components->error($open->isEmpty()
            ? 'There are no open rooms.'
            : 'More than one room is open, pass an invite code: '.$open->pluck('invite_code')->implode(', '));

        return null;
    }

    private function summariseRoom(Room $room, ?SpotifyAccount $account): void
    {
        $this->components->info("Room {$room->invite_code}");
        $this->components->twoColumnDetail('Stored playing / inactive', ($room->is_playing ? 'playing' : 'not playing').($room->playbackInactive() ? ' / inactive since '.$room->playback_inactive_at : ''));
        $this->components->twoColumnDetail('Stored shuffle / repeat', ($room->shuffle_enabled ? 'on' : 'off').' / '.$room->repeat_mode);
        $this->components->twoColumnDetail('Stored track', $room->now_playing_track_id ? "{$room->now_playing_name} ({$room->now_playing_track_id})" : '(none)');
        $this->components->twoColumnDetail('Stored playlist (fallback_playlist_uri)', $room->fallback_playlist_uri ?: '(none)');
        $this->components->twoColumnDetail('Spotify account', $account
            ? 'connected'.($account->needsReconnect() ? ', FLAGGED needs reconnect' : '').', token '.($account->isTokenExpired() ? 'expired' : 'valid until '.$account->token_expires_at)
            : 'none');
    }

    private function showDevices(array $devices): void
    {
        $this->newLine();
        $this->components->info('Devices ('.$this->describe($devices).')');

        foreach ($devices['json']['devices'] ?? [] as $device) {
            $this->line(sprintf('  %s [%s] %s', $device['name'] ?? '?', $device['type'] ?? '?', ($device['is_active'] ?? false) ? 'ACTIVE' : 'inactive'));
        }

        if (empty($devices['json']['devices'])) {
            $this->line('  (none listed)');
        }
    }

    /** @return array<string, mixed>|null the raw /me/player body, or null when nothing is active */
    private function showPlayback(Room $room, array $player): ?array
    {
        $this->newLine();
        $this->components->info('Playback state ('.$this->describe($player).')');

        if ($player['status'] === 204 || ! $player['json']) {
            $this->line('  Nothing active anywhere (HTTP 204). The app marks the room inactive in this case.');

            return null;
        }

        $state = $player['json'];
        $item = $state['item'] ?? [];
        $context = $state['context'] ?? null;
        $contextUri = $context['uri'] ?? null;

        $this->components->twoColumnDetail('Current track', ($item['name'] ?? '(none)').' ('.($item['id'] ?? '-').')');
        $this->components->twoColumnDetail('Playing / progress', (($state['is_playing'] ?? false) ? 'playing' : 'paused').' / '.round(($state['progress_ms'] ?? 0) / 1000).'s of '.round(($item['duration_ms'] ?? 0) / 1000).'s');
        $this->components->twoColumnDetail('Shuffle / repeat', (($state['shuffle_state'] ?? false) ? 'on' : 'off').' / '.($state['repeat_state'] ?? '?'));
        $this->components->twoColumnDetail('Context', $context ? "{$context['type']}  {$contextUri}" : '(none: a single track, the queue, or radio)');

        $disallows = array_keys(array_filter($state['actions']['disallows'] ?? []));
        $this->components->twoColumnDetail('Spotify says these are disallowed', $disallows ? implode(', ', $disallows) : '(nothing)');

        // Why clicking a "coming up" track can do nothing: the app only knows how to jump within a
        // playlist it started itself.
        if ($contextUri) {
            $stored = $room->fallback_playlist_uri;
            $verdict = ! $stored
                ? 'ROOM HAS NO PLAYLIST STORED: clicking a coming-up track currently does nothing'
                : ($stored === $contextUri ? 'matches the room' : "DIFFERENT from the room's stored playlist ({$stored})");
            $this->components->twoColumnDetail('Playing context vs room', $verdict);
        }

        return $state;
    }

    /** @return list<string> ids in the order Spotify returned them */
    private function showQueue(array $queue, ?array $state): array
    {
        $this->newLine();
        $this->components->info('Queue as Spotify returns it ('.$this->describe($queue).')');

        $current = $queue['json']['currently_playing']['id'] ?? ($state['item']['id'] ?? null);
        $items = $queue['json']['queue'] ?? [];
        $ids = [];
        $seen = [];

        foreach ($items as $i => $track) {
            $id = $track['id'] ?? null;
            $ids[] = $id;
            $flags = [];

            if ($id && $id === $current) {
                $flags[] = 'THE CURRENT TRACK AGAIN';
            }

            if ($id && isset($seen[$id])) {
                $flags[] = "repeat of #{$seen[$id]}";
            }

            $seen[$id] ??= $i + 1;

            if ($i < 25) {
                $this->line(sprintf('  %2d. %s%s', $i + 1, $track['name'] ?? '?', $flags ? '   <-- '.implode(', ', $flags) : ''));
            }
        }

        if (count($items) > 25) {
            $this->line('  ... '.(count($items) - 25).' more');
        }

        $this->components->twoColumnDetail('Length / distinct tracks', count($items).' / '.count(array_unique(array_filter($ids))));

        return array_values(array_filter($ids));
    }

    /** @return list<string>|null the context's track ids in play order, or null when unreadable */
    private function showContext(SpotifyAccount $account, ?array $state, array $queueIds, array &$saved): ?array
    {
        $this->newLine();
        $this->components->info('Where the current track sits in its playlist/album');

        $uri = $state['context']['uri'] ?? null;
        $type = $state['context']['type'] ?? null;

        if (! $uri || ! in_array($type, ['playlist', 'album'], true)) {
            $this->line('  Not a playlist or album context, so there is no list order to compare against.');

            return null;
        }

        $id = substr($uri, strrpos($uri, ':') + 1);
        $limit = max(1, (int) $this->option('tracks'));
        $ids = [];
        $names = [];
        $report = [];

        // Spotify renamed this endpoint in Feb 2026; try the new path, then the old one.
        $paths = $type === 'playlist' ? ["/playlists/{$id}/items", "/playlists/{$id}/tracks"] : ["/albums/{$id}/tracks"];

        foreach ($paths as $path) {
            $offset = 0;
            $ids = [];
            $names = [];
            $first = null;

            do {
                $page = $this->get($account, $path, ['limit' => 50, 'offset' => $offset, 'market' => 'from_token']);
                $first ??= $page;

                foreach ($page['json']['items'] ?? [] as $row) {
                    // playlists: items[].item (new) or items[].track (old); albums: items[] are tracks
                    $track = $type === 'album' ? $row : ($row['item'] ?? $row['track'] ?? null);

                    if (is_array($track) && ! empty($track['id'])) {
                        $ids[] = $track['id'];
                        $names[$track['id']] = $track['name'] ?? '?';
                    }
                }

                $offset += 50;
                $more = ! empty($page['json']['next']) && count($ids) < $limit;
            } while ($more);

            $report[$path] = $first;

            if ($ids) {
                $saved['context'] = $first['json'];

                break;
            }
        }

        foreach ($report as $path => $page) {
            $this->components->twoColumnDetail("GET {$path}", $this->describe($page).(isset($page['json']['items']) ? ', items readable' : ', NO items in the response'));
        }

        if (! $ids) {
            $this->line('  Spotify did not return this context\'s tracks. For apps in Development Mode it only returns contents of playlists you own, so the real order can\'t be known for this one.');

            return null;
        }

        $this->components->twoColumnDetail('Tracks read', count($ids).(isset($page['json']['total']) ? ' of '.$page['json']['total'] : ''));

        $currentId = $state['item']['id'] ?? null;
        $positions = array_keys($ids, $currentId, true);

        if (! $positions) {
            $this->line('  The current track is not in the part of the list that was read (raise --tracks, or it is a queued track).');

            return $ids;
        }

        // A track can appear twice in a playlist: prefer the occurrence followed by what the queue says is next.
        $position = $positions[0];

        foreach ($positions as $candidate) {
            if (isset($queueIds[0], $ids[$candidate + 1]) && $ids[$candidate + 1] === $queueIds[0]) {
                $position = $candidate;
            }
        }

        $remaining = array_slice($ids, $position + 1);

        $this->components->twoColumnDetail('Current track is number', ($position + 1).' of '.count($ids));
        $this->components->twoColumnDetail('Tracks genuinely left after it', (string) count($remaining));

        foreach (array_slice($remaining, 0, 10) as $i => $trackId) {
            $this->line(sprintf('  %2d. %s', $i + 1, $names[$trackId] ?? $trackId));
        }

        return $ids;
    }

    private function conclude(?array $state, array $queueIds, ?array $contextIds): void
    {
        $this->newLine();
        $this->components->info('What this means');

        if (! $state) {
            $this->line('  Nothing is active, so there is no queue behavior to judge. Start something playing and run this again.');

            return;
        }

        $repeat = $state['repeat_state'] ?? 'off';
        $shuffle = (bool) ($state['shuffle_state'] ?? false);

        if ($shuffle) {
            $this->line('  Shuffle is on: the queue is the shuffled order, so context order is not comparable. Turn shuffle off to test the wrap-around.');

            return;
        }

        if ($contextIds === null) {
            $this->line('  The context\'s order could not be read, so the wrap-around can only be judged from the queue itself (see the flagged lines above).');

            return;
        }

        $currentId = $state['item']['id'] ?? null;
        $position = array_search($currentId, $contextIds, true);

        if ($position === false) {
            return;
        }

        $remaining = array_slice($contextIds, $position + 1);
        $padding = count($queueIds) - count($remaining);

        if ($repeat === 'off' && $padding > 0 && array_slice($queueIds, 0, count($remaining)) === $remaining) {
            $this->components->warn("  Repeat is off, but the queue lists {$padding} track(s) beyond the real end of the list. Spotify pads it by wrapping; playback will not actually loop.");
        } elseif ($repeat === 'off') {
            $this->line('  Repeat is off and the queue matches what is genuinely left. Nothing is padded right now.');
        } else {
            $this->line("  Repeat is '{$repeat}', so a wrapped queue is real here.");
        }
    }

    /** @return array{status: int, json: ?array, error: ?string} */
    private function get(SpotifyAccount $account, string $path, array $query = []): array
    {
        try {
            $response = Http::withToken($account->access_token)->acceptJson()->timeout(10)
                ->get('https://api.spotify.com/v1'.$path, $query);
        } catch (ConnectionException $e) {
            return ['status' => 0, 'json' => null, 'error' => $e->getMessage()];
        }

        return ['status' => $response->status(), 'json' => $response->json(), 'error' => null];
    }

    private function describe(array $result): string
    {
        return $result['error'] ? 'failed: '.$result['error'] : 'HTTP '.$result['status'];
    }

    /** @param array<string, mixed> $saved */
    private function save(string $directory, array $saved): void
    {
        File::ensureDirectoryExists($directory);

        foreach ($saved as $name => $body) {
            if ($body === null) {
                continue;
            }

            File::put("{$directory}/{$name}.json", json_encode($this->sanitize($body), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->newLine();
        $this->components->info("Sanitized fixtures written to {$directory}");
    }

    private function sanitize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $clean = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::STRIP_KEYS, true)) {
                continue;
            }

            $clean[$key] = $this->sanitize($item);
        }

        // A device id/name identifies someone's hardware; nothing in a test needs the real ones.
        if (isset($clean['is_active'], $clean['type'], $clean['name'])) {
            $clean['id'] = 'device-1';
            $clean['name'] = 'Test device';
        }

        return $clean;
    }
}
