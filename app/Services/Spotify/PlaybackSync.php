<?php

namespace App\Services\Spotify;

use App\Events\RoomUpdated;
use App\Models\ActivityEvent;
use App\Models\Room;

/**
 * Detects playback changes made outside AuxRoom — pausing, seeking, or
 * skipping from the Spotify app itself, or any other Spotify Connect
 * client — and pulls the room's state back in line with reality.
 *
 * Shared by both the host's dashboard (ShowRoom) and the Party Screen, so
 * a Party Screen left open on its own (no dashboard tab kept open
 * alongside it) still notices a track change on its own instead of
 * depending on the host's session to poll Spotify and relay it — that
 * relay could add several seconds of extra lag, or never happen at all if
 * the host's tab isn't open.
 */
class PlaybackSync
{
    public function __construct(private SpotifyClientFactory $clients) {}

    /**
     * $actingMemberId attributes the resulting "Now playing from Spotify"
     * activity log entry, if one is written; null (e.g. from the Party
     * Screen, which isn't tied to any one visitor) just leaves it unset
     * rather than mis-attributing it.
     */
    public function sync(Room $room, ?int $actingMemberId = null): void
    {
        if ($this->clients->isMock($room) || ! $room->playbackProvider?->hasSpotifyConnected()) {
            return;
        }

        $client = $this->clients->forRoom($room);

        try {
            $state = $client->getPlaybackState();
        } catch (SpotifyRequestFailed) {
            // An outage or rate limit says nothing about what's playing, so
            // leave the room's last known state alone rather than clearing it.
            return;
        }

        if (! $state) {
            $this->handleNothingActive($room, $client);

            return;
        }

        // Spotify's own context tells us, authoritatively, whether it's
        // currently playing from the selected playlist — rather than
        // trusting a locally-toggled flag that can go stale once native
        // queue items start interleaving with the underlying context.
        $isFallbackContext = $room->fallback_playlist_uri
            && $state['context_uri'] === $room->fallback_playlist_uri;

        $trackChanged = $state['track_id'] !== $room->now_playing_track_id;
        $queueItemId = $room->now_playing_queue_item_id;

        if ($trackChanged) {
            // Look up whether a guest explicitly queued this, purely for
            // "added by" attribution — Spotify's own queue/context is the
            // source of truth for order and content, never reconstructed
            // locally (that's what caused the queue to go backwards before).
            $matched = $state['track_id']
                ? $room->queueItems()->where('spotify_track_id', $state['track_id'])->whereNull('played_at')->first()
                : null;

            $matched?->update(['played_at' => now()]);
            $queueItemId = $matched?->id;

            if (! $isFallbackContext && ! $matched && $state['track_id']) {
                ActivityEvent::create([
                    'room_id' => $room->id,
                    'member_id' => $actingMemberId,
                    'type' => 'played',
                    'message' => "Now playing from Spotify: \"{$state['name']}\".",
                ]);
            }
        }

        $drifted = abs($state['progress_ms'] - $room->currentPositionMs()) > 3000;
        $playStateChanged = $state['is_playing'] !== $room->is_playing;
        $fallbackFlagChanged = $isFallbackContext !== $room->is_playing_fallback;
        $shuffleChanged = $state['shuffle_enabled'] !== $room->shuffle_enabled;
        $repeatChanged = $state['repeat_mode'] !== $room->repeat_mode;

        if (! $drifted && ! $playStateChanged && ! $fallbackFlagChanged && ! $trackChanged && ! $shuffleChanged && ! $repeatChanged) {
            return;
        }

        $room->update([
            'now_playing_queue_item_id' => $queueItemId,
            'now_playing_track_id' => $state['track_id'],
            'now_playing_name' => $state['name'],
            'now_playing_artist' => $state['artist'],
            'now_playing_album_art_url' => $state['album_art_url'],
            'now_playing_duration_ms' => $state['duration_ms'],
            'is_playing_fallback' => $isFallbackContext,
            'is_playing' => $state['is_playing'],
            'now_playing_position_ms' => $state['progress_ms'],
            'now_playing_started_at' => $state['is_playing'] ? now()->subMilliseconds($state['progress_ms']) : null,
            'shuffle_enabled' => $state['shuffle_enabled'],
            'repeat_mode' => $state['repeat_mode'],
        ]);

        RoomUpdated::broadcastFor($room, 'playback');
    }

    /**
     * Spotify returned 204: no *active* device. That also happens when a
     * paused device has simply idled out, in which case the device is still
     * listed and the stored track and position must survive, since resuming
     * re-issues that exact track at that exact position. Only when no device
     * is visible at all (the app was closed, the speaker went away) is the
     * now-playing state cleared, so the dashboard says "no device" instead
     * of showing a dead track forever.
     */
    private function handleNothingActive(Room $room, SpotifyClientContract $client): void
    {
        if (! $room->is_playing && ! $room->now_playing_track_id) {
            return;
        }

        if ($client->getDevices() !== []) {
            if ($room->is_playing) {
                $room->update(['is_playing' => false]);
                RoomUpdated::broadcastFor($room, 'playback');
            }

            return;
        }

        $room->update([
            'is_playing' => false,
            'now_playing_queue_item_id' => null,
            'now_playing_track_id' => null,
            'now_playing_name' => null,
            'now_playing_artist' => null,
            'now_playing_album_art_url' => null,
            'now_playing_duration_ms' => null,
            'now_playing_started_at' => null,
            'now_playing_position_ms' => 0,
        ]);
        RoomUpdated::broadcastFor($room, 'playback');
    }
}
