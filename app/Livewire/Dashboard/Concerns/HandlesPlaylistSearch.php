<?php

namespace App\Livewire\Dashboard\Concerns;

use App\Services\Spotify\SpotifyClientFactory;

/** Track search + add-to-queue, and the playlist picker popup (search, browse, pick-and-play). */
trait HandlesPlaylistSearch
{
    public string $search = '';

    /** @var array<int, array> */
    public array $searchResults = [];

    public bool $showPlaylistPicker = false;

    public string $playlistQuery = '';

    /** @var array<int, array> */
    public array $playlistResults = [];

    public function updatedSearch(): void
    {
        if (trim($this->search) !== '') {
            $this->activeTab = 'queue';
        }

        $this->search();
    }

    public function search(): void
    {
        $this->controlError = '';

        if (trim($this->search) === '') {
            $this->searchResults = [];

            return;
        }

        $this->searchResults = app(SpotifyClientFactory::class)->forRoom($this->room)->search($this->search, 8);
    }

    public function addToQueue(int $index): void
    {
        if (! $this->passesGate('guests_can_add_to_queue')) {
            return;
        }

        $track = $this->searchResults[$index] ?? null;

        if (! $track) {
            return;
        }

        $nextPosition = (int) ($this->room->queueItems()->max('position') ?? 0) + 1;

        $item = $this->room->queueItems()->create([
            'added_by_id' => $this->member->id,
            'spotify_track_id' => $track['id'],
            'name' => $track['name'],
            'artist' => $track['artist'],
            'album_art_url' => $track['album_art_url'],
            'duration_ms' => $track['duration_ms'],
            'position' => $nextPosition,
        ]);

        $this->logActivity('queued', "{$this->member->display_name} added \"{$item->name}\" to the queue.");

        if (! $this->room->now_playing_track_id && ! $this->room->is_playing) {
            // Nothing playing at all yet — this is the first song, so start it.
            $this->startPlayback($item);
        } else {
            // Something's already playing (a queued track or the fallback
            // playlist) — queue this one to play next without interrupting
            // it, the same as swiping right on a song in Spotify itself.
            $client = app(SpotifyClientFactory::class)->forRoom($this->room);

            if (! $client->addToPlaybackQueue('spotify:track:'.$item->spotify_track_id, $this->providerDeviceId())) {
                $this->controlError = "Spotify couldn't queue that song. Try reselecting the device in Host Hub.";
            }
        }

        $this->broadcastUpdate('queue');

        $this->search = '';
        $this->searchResults = [];
    }

    /** Opens the playlist picker popup, pre-loaded with the host's own playlists. */
    public function openPlaylistPicker(): void
    {
        if (! $this->passesGate('guests_can_manage_playlist')) {
            return;
        }

        $this->showPlaylistPicker = true;
        $this->browseMyPlaylists();
    }

    public function closePlaylistPicker(): void
    {
        $this->showPlaylistPicker = false;
        $this->playlistQuery = '';
        $this->playlistResults = [];
    }

    public function updatedPlaylistQuery(): void
    {
        $this->searchPlaylists();
    }

    public function searchPlaylists(): void
    {
        if (! $this->passesGate('guests_can_manage_playlist')) {
            $this->playlistResults = [];

            return;
        }

        $query = trim($this->playlistQuery);

        if ($query === '') {
            $this->playlistResults = [];

            return;
        }

        $client = app(SpotifyClientFactory::class)->forRoom($this->room);

        // A pasted playlist link resolves directly instead of going through search.
        if ($id = $this->parsePlaylistId($query)) {
            $playlist = $client->getPlaylist($id);
            $this->playlistResults = $playlist ? [$playlist] : [];

            return;
        }

        $this->playlistResults = $client->searchPlaylists($query, 8);
    }

    public function browseMyPlaylists(): void
    {
        if (! $this->passesGate('guests_can_manage_playlist')) {
            return;
        }

        $this->playlistQuery = '';
        $this->playlistResults = app(SpotifyClientFactory::class)->forRoom($this->room)->myPlaylists();
    }

    /**
     * Picks a playlist and starts playing it immediately — the same as
     * tapping a playlist and hitting play in Spotify itself. Queued songs
     * still play next without interrupting it, and it resumes on its own
     * once the queue drains, since we never replace its context to do that.
     */
    public function playPlaylist(int $index): void
    {
        if (! $this->passesGate('guests_can_manage_playlist')) {
            return;
        }

        $playlist = $this->playlistResults[$index] ?? null;

        if (! $playlist) {
            return;
        }

        $this->room->update([
            'fallback_playlist_uri' => $playlist['uri'],
            'fallback_playlist_name' => $playlist['name'],
            'fallback_playlist_image_url' => $playlist['image_url'],
        ]);

        if (! $this->startFallbackPlayback()) {
            return;
        }

        $this->closePlaylistPicker();

        // Learn the actual track Spotify picked to start with right away,
        // instead of waiting up to 3s for the next heartbeat poll.
        $this->syncWithSpotify();

        $this->broadcastUpdate('playback');
    }

    /**
     * Jumps straight to a specific track from the currently-playing
     * playlist, the same as clicking a song inside a playlist in Spotify
     * itself: it plays immediately, and the playlist's own native order
     * (and auto-advance) resumes normally from there afterward.
     */
    public function playFromPlaylist(string $trackId): void
    {
        if (! $this->passesGate('guests_can_manage_playlist')) {
            return;
        }

        if (! $this->room->fallback_playlist_uri) {
            return;
        }

        // Looked up from Spotify's own live queue rather than trusted from
        // the client, the same way every other track action in this class
        // treats Spotify's queue as the only source of truth for content.
        $track = $this->queue->firstWhere('spotify_track_id', $trackId);

        if (! $track) {
            return;
        }

        $client = app(SpotifyClientFactory::class)->forRoom($this->room);

        if (! $client->playContextAtTrack($this->room->fallback_playlist_uri, 'spotify:track:'.$trackId, 0, $this->providerDeviceId())) {
            $this->controlError = "Spotify couldn't play that track. Try reselecting the device in Host Hub.";

            return;
        }

        $this->room->update([
            'now_playing_queue_item_id' => null,
            'now_playing_track_id' => $trackId,
            'now_playing_name' => $track->name,
            'now_playing_artist' => $track->artist,
            'now_playing_album_art_url' => $track->album_art_url,
            'now_playing_duration_ms' => $track->duration_ms,
            'now_playing_started_at' => now(),
            'now_playing_position_ms' => 0,
            'is_playing' => true,
            'is_playing_fallback' => true,
            'last_local_command_at' => now(),
        ]);

        $this->logActivity('played', "{$this->member->display_name} jumped to \"{$track->name}\" in the playlist.");
        $this->broadcastUpdate('playback');
    }

    /** Pulls a bare playlist ID out of a pasted Spotify link or URI, if it looks like one. */
    private function parsePlaylistId(string $input): ?string
    {
        if (preg_match('#playlist[/:]([A-Za-z0-9]{10,30})#', trim($input), $matches)) {
            return $matches[1];
        }

        return null;
    }
}
