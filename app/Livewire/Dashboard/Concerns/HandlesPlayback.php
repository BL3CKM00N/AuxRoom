<?php

namespace App\Livewire\Dashboard\Concerns;

use App\Models\QueueItem;
use App\Services\Spotify\PlaybackSync;
use App\Services\Spotify\SpotifyClientFactory;

/** Transport controls (play/pause/skip/seek/volume/shuffle/repeat), device selection, and the low-level "start playback" primitives they share. */
trait HandlesPlayback
{
    /**
     * Detects playback changes made outside AuxRoom (see PlaybackSync). Only
     * the host's heartbeat triggers this on the dashboard side, since it's
     * the host's Spotify account being queried and every guest's heartbeat
     * would otherwise multiply calls — the Party Screen also triggers its
     * own copy independently, so it stays live without needing this tab open.
     */
    private function syncWithSpotify(): void
    {
        app(PlaybackSync::class)->sync($this->room, $this->member->id);
    }

    public function play(): void
    {
        if (! $this->passesGate('guests_can_play_pause')) {
            return;
        }

        if ($this->room->is_playing) {
            return;
        }

        if ($this->room->now_playing_track_id) {
            // A track playing from the fallback playlist's context resumes
            // via that same context (offset to this track, at this exact
            // position) rather than a bare track URI — a bare URI replaces
            // the context entirely, and once that track ends Spotify has
            // nothing left to advance to, so every future resume just
            // replays that same now-context-less track forever.
            if ($this->room->is_playing_fallback && $this->room->fallback_playlist_uri) {
                if (! app(SpotifyClientFactory::class)->forRoom($this->room)->resumeContext(
                    $this->room->fallback_playlist_uri,
                    'spotify:track:'.$this->room->now_playing_track_id,
                    $this->room->now_playing_position_ms,
                    $this->room->shuffle_enabled,
                    $this->providerDeviceId()
                )) {
                    $this->controlError = "Spotify couldn't resume the playlist. Try reselecting the device in Host Hub.";

                    return;
                }

                $this->room->update([
                    'is_playing' => true,
                    'now_playing_started_at' => now()->subMilliseconds($this->room->now_playing_position_ms),
                    'last_local_command_at' => now(),
                ]);

                $this->logActivity('played', "Playback resumed: \"{$this->room->now_playing_name}\".");
                $this->broadcastUpdate('playback');

                return;
            }

            // A standalone queued track has no context to preserve, so
            // re-issuing it directly at its exact position is safe.
            if (! $this->playTrackAt(
                $this->room->now_playing_track_id,
                $this->room->now_playing_name ?? 'Unknown track',
                $this->room->now_playing_artist ?? '',
                $this->room->now_playing_album_art_url,
                $this->room->now_playing_duration_ms ?? 0,
                $this->room->now_playing_position_ms,
                $this->room->now_playing_queue_item_id
            )) {
                return;
            }

            $this->logActivity('played', "Playback resumed: \"{$this->room->now_playing_name}\".");
            $this->broadcastUpdate('playback');

            return;
        }

        $next = $this->room->pendingQueueItems()->first();

        if ($next) {
            $this->startPlayback($next);
            $this->broadcastUpdate('playback');

            return;
        }

        if ($this->room->fallback_playlist_uri) {
            if ($this->startFallbackPlayback()) {
                $this->syncWithSpotify();
            }

            $this->broadcastUpdate('playback');
        }
    }

    public function pause(): void
    {
        if (! $this->passesGate('guests_can_play_pause')) {
            return;
        }

        if (! $this->room->is_playing) {
            return;
        }

        $position = $this->room->currentPositionMs();

        if (! app(SpotifyClientFactory::class)->forRoom($this->room)->pause($this->providerDeviceId())) {
            $this->controlError = "Spotify couldn't pause playback.";

            return;
        }

        $this->room->update([
            'is_playing' => false,
            'now_playing_position_ms' => $position,
            'last_local_command_at' => now(),
        ]);

        $this->logActivity('paused', 'Playback paused.');
        $this->broadcastUpdate('playback');
    }

    /**
     * Skips via Spotify's own "next" transport control, not a local guess —
     * this respects whatever Spotify actually has queued/shuffled next,
     * including tracks from a playing playlist we don't know the order of.
     */
    public function skip(): void
    {
        if (! $this->passesGate('guests_can_skip')) {
            return;
        }

        if (! app(SpotifyClientFactory::class)->forRoom($this->room)->skipToNext($this->providerDeviceId())) {
            $this->controlError = "Spotify couldn't skip the track.";

            return;
        }

        $this->room->update(['last_local_command_at' => now()]);
        $this->logActivity('skipped', "{$this->member->display_name} skipped the track.");
        $this->syncWithSpotify();
        $this->broadcastUpdate('playback');
    }

    public function previous(): void
    {
        if (! $this->passesGate('guests_can_skip')) {
            return;
        }

        if (! app(SpotifyClientFactory::class)->forRoom($this->room)->skipToPrevious($this->providerDeviceId())) {
            $this->controlError = "Spotify couldn't go back a track.";

            return;
        }

        $this->room->update(['last_local_command_at' => now()]);
        $this->logActivity('skipped', "{$this->member->display_name} went back a track.");
        $this->syncWithSpotify();
        $this->broadcastUpdate('playback');
    }

    public function toggleShuffle(): void
    {
        if (! $this->passesGate('guests_can_play_pause')) {
            return;
        }

        $enabled = ! $this->room->shuffle_enabled;

        if (! app(SpotifyClientFactory::class)->forRoom($this->room)->setShuffle($enabled, $this->providerDeviceId())) {
            $this->controlError = "Spotify couldn't change shuffle.";

            return;
        }

        $this->room->update(['shuffle_enabled' => $enabled, 'last_local_command_at' => now()]);
        $this->broadcastUpdate('playback');
    }

    public function toggleRepeat(): void
    {
        if (! $this->passesGate('guests_can_play_pause')) {
            return;
        }

        $modes = ['off', 'context', 'track'];
        $next = $modes[(array_search($this->room->repeat_mode, $modes, true) + 1) % count($modes)];

        if (! app(SpotifyClientFactory::class)->forRoom($this->room)->setRepeat($next, $this->providerDeviceId())) {
            $this->controlError = "Spotify couldn't change repeat mode.";

            return;
        }

        $this->room->update(['repeat_mode' => $next, 'last_local_command_at' => now()]);
        $this->broadcastUpdate('playback');
    }

    public function seek(int $ms): void
    {
        if (! $this->passesGate('guests_can_seek')) {
            return;
        }

        if (! $this->room->now_playing_track_id) {
            return;
        }

        if (! app(SpotifyClientFactory::class)->forRoom($this->room)->seek($ms, $this->providerDeviceId())) {
            $this->controlError = "Spotify couldn't seek playback.";

            return;
        }

        $this->room->update([
            'now_playing_position_ms' => $ms,
            'now_playing_started_at' => $this->room->is_playing ? now()->subMilliseconds($ms) : null,
            'last_local_command_at' => now(),
        ]);

        $this->broadcastUpdate('playback');
    }

    public function setVolume(int $percent): void
    {
        if (! $this->passesGate('guests_can_set_volume')) {
            return;
        }

        $percent = max(0, min(100, $percent));

        if (! app(SpotifyClientFactory::class)->forRoom($this->room)->setVolume($percent, $this->providerDeviceId())) {
            $this->controlError = "Spotify couldn't change the volume.";

            return;
        }

        $this->room->update(['volume_percent' => $percent]);
        $this->broadcastUpdate('playback');
    }

    public function switchProvider(int $userId): void
    {
        if (! $this->isHost) {
            return;
        }

        $this->room->update(['playback_provider_id' => $userId]);
        $this->logActivity('device_changed', 'Playback source switched.');
        $this->broadcastUpdate('settings');
    }

    public function selectDevice(string $deviceId, string $deviceName): void
    {
        if (! $this->isHost) {
            return;
        }

        $this->room->playbackProvider?->spotifyAccount?->update([
            'active_device_id' => $deviceId,
            'active_device_name' => $deviceName,
        ]);

        $this->logActivity('device_changed', "Playback device set to \"{$deviceName}\".");
        $this->broadcastUpdate('settings');
    }

    /**
     * Prefers whatever device Spotify currently reports as active over a
     * manually-picked one — the manual "Choose device" picker in Host Hub
     * only mattered because nothing auto-detected this before. Falls back
     * to the last manual pick if nothing is currently active anywhere.
     */
    private function providerDeviceId(): ?string
    {
        $account = $this->room->playbackProvider?->spotifyAccount;

        if (! $account || ! $account->access_token) {
            return $account?->active_device_id;
        }

        $active = collect(app(SpotifyClientFactory::class)->forRoom($this->room)->getDevices())
            ->firstWhere('is_active', true);

        if ($active) {
            if ($account->active_device_id !== $active['id']) {
                $account->update(['active_device_id' => $active['id'], 'active_device_name' => $active['name']]);
            }

            return $active['id'];
        }

        return $account->active_device_id;
    }

    /**
     * Spotify's bare "resume" (an empty-body PUT to /player/play) relies on
     * its backend still remembering the paused context, which it silently
     * drops often enough that resuming this way was unreliable. Re-issuing
     * the exact track at its stored position works the same for the
     * listener but doesn't depend on Spotify remembering anything.
     *
     * Takes track details directly rather than a QueueItem, since "now
     * playing" isn't necessarily backed by one (a shuffled playlist track,
     * for instance) — the room's own now_playing_* fields are always the
     * source of truth for what's currently playing.
     */
    private function playTrackAt(
        string $spotifyTrackId,
        string $name,
        string $artist,
        ?string $albumArtUrl,
        int $durationMs,
        int $positionMs,
        ?int $queueItemId = null
    ): bool {
        $client = app(SpotifyClientFactory::class)->forRoom($this->room);

        if (! $client->playTrack('spotify:track:'.$spotifyTrackId, $this->providerDeviceId(), $positionMs)) {
            $this->controlError = "Spotify couldn't start playback. Try reselecting the device in Host Hub.";

            return false;
        }

        $this->room->update([
            'now_playing_queue_item_id' => $queueItemId,
            'now_playing_track_id' => $spotifyTrackId,
            'now_playing_name' => $name,
            'now_playing_artist' => $artist,
            'now_playing_album_art_url' => $albumArtUrl,
            'now_playing_duration_ms' => $durationMs,
            'now_playing_started_at' => now()->subMilliseconds($positionMs),
            'now_playing_position_ms' => $positionMs,
            'is_playing' => true,
            'is_playing_fallback' => false,
            'last_local_command_at' => now(),
        ]);

        return true;
    }

    private function startPlayback(QueueItem $item): bool
    {
        if (! $this->playTrackAt($item->spotify_track_id, $item->name, $item->artist, $item->album_art_url, $item->duration_ms, 0, $item->id)) {
            return false;
        }

        $this->logActivity('played', "Now playing: \"{$item->name}\" by {$item->artist}.");

        return true;
    }

    /**
     * Hands playback off to Spotify's own shuffled playback of the fallback
     * playlist. AuxRoom doesn't manage its tracks one by one — Spotify keeps
     * it going on its own until a guest queues something, which interrupts it.
     */
    private function startFallbackPlayback(): bool
    {
        $client = app(SpotifyClientFactory::class)->forRoom($this->room);

        if (! $client->playContext($this->room->fallback_playlist_uri, $this->providerDeviceId(), true)) {
            $this->controlError = "Spotify couldn't start the fallback playlist. Try reselecting the device in Host Hub.";

            return false;
        }

        $this->room->update([
            'now_playing_queue_item_id' => null,
            'now_playing_track_id' => null,
            'now_playing_name' => null,
            'now_playing_artist' => null,
            'now_playing_album_art_url' => null,
            'now_playing_duration_ms' => null,
            'now_playing_started_at' => now(),
            'now_playing_position_ms' => 0,
            'is_playing' => true,
            'is_playing_fallback' => true,
            'last_local_command_at' => now(),
        ]);

        $this->logActivity('played', "Fallback playlist started: \"{$this->room->fallback_playlist_name}\".");

        return true;
    }
}
