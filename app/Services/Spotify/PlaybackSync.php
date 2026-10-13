<?php

namespace App\Services\Spotify;

use App\Events\RoomUpdated;
use App\Models\ActivityEvent;
use App\Models\PlayedTrack;
use App\Models\Room;
use App\Services\AlbumTint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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
    /** A track this close to its start, paused, is "parked", not something a person paused mid-song. */
    private const PARKED_AT_START_MS = 3000;

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

        try {
            $state = $this->clients->forRoom($room)->getPlaybackState();
        } catch (SpotifyRequestFailed) {
            // An outage or rate limit says nothing about what's playing, so
            // leave the room's last known state alone rather than clearing it.
            return;
        }

        WatchState::markVerified($room);
        $this->applyState($room, $state, $actingMemberId);
    }

    /**
     * Opening a room whose state nobody has checked lately (a new room, or one nobody was watching) would show
     * what was stored, possibly nothing, until the watcher catches up. So the page asks Spotify once itself.
     * Not when the watcher keeps the room fresh, nor right after another command or a very recent look.
     */
    public function refreshIfStale(Room $room, int $maxAgeSeconds = 5): bool
    {
        $age = WatchState::verifiedAge($room);

        if (($age !== null && $age < $maxAgeSeconds) || $room->commandedRecently() || ! Cache::add("sync-on-open:{$room->id}", 1, 3)) {
            return false;
        }

        $this->sync($room);

        return true;
    }

    /**
     * Brings the room in line with a playback state Spotify just reported (null: nothing
     * active). Split from sync() so the background watcher, which decides when an answer
     * can be trusted (see RoomWatcher), can apply exactly the state it chose.
     */
    public function applyState(Room $room, ?array $state, ?int $actingMemberId = null): void
    {
        if (! $state) {
            $this->markInactive($room);

            return;
        }

        // Spotify's own context tells us, authoritatively, whether it's
        // currently playing from the selected playlist — rather than
        // trusting a locally-toggled flag that can go stale once native
        // queue items start interleaving with the underlying context.
        $isFallbackContext = $room->fallback_playlist_uri
            && $state['context_uri'] === $room->fallback_playlist_uri;

        $trackChanged = $state['track_id'] !== $room->now_playing_track_id;
        $finished = $this->playlistJustFinished($room, $state, $trackChanged);
        $queueItemId = $room->now_playing_queue_item_id;

        if ($trackChanged) {
            // Look up whether a guest explicitly queued this, purely for
            // "added by" attribution — Spotify's own queue/context is the
            // source of truth for order and content, never reconstructed
            // locally (that's what caused the queue to go backwards before).
            $matched = $state['track_id']
                ? $room->queueItems()->released()->where('spotify_track_id', $state['track_id'])->whereNull('played_at')->orderBy('released_at')->orderBy('id')->first()
                : null;

            $matched?->update(['played_at' => now()]);
            $queueItemId = $matched?->id;

            // Whether this change cut the last song short (a skip), for the waiting list to tell a skip from a song ending.
            Cache::put("track-change:{$room->id}", [
                'track' => $state['track_id'],
                'early' => $room->now_playing_duration_ms > 0 && $room->currentPositionMs() < $room->now_playing_duration_ms - 6000,
            ], 15);

            if (! $finished) {
                PlayedTrack::record($room, $state['track_id']);
            }

            if (! $isFallbackContext && ! $matched && $state['track_id'] && ! $finished) {
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
        $shuffleChanged = $state['shuffle_enabled'] !== $room->shuffle_enabled
            || $state['smart_shuffle_enabled'] !== $room->smart_shuffle_enabled;
        $repeatChanged = $state['repeat_mode'] !== $room->repeat_mode;
        // Volume changed on the device or in the Spotify app, not through AuxRoom.
        $volumeChanged = ($state['volume_percent'] !== null && $state['volume_percent'] !== $room->volume_percent)
            || $state['supports_volume'] !== $room->volume_supported;
        $contextChanged = $state['context_uri'] !== $room->now_playing_context_uri;
        $wasInactive = $room->playback_inactive_at !== null;
        // After a skip prediction the track is right but its artists are not known yet.
        $detailsMissing = $room->now_playing_artists === null && ! empty($state['artists']);

        if (! $detailsMissing && ! $wasInactive && ! $volumeChanged && ! $contextChanged && ! $drifted && ! $playStateChanged && ! $fallbackFlagChanged && ! $trackChanged && ! $shuffleChanged && ! $repeatChanged) {
            $this->tint($room);

            return;
        }

        $room->update([
            'now_playing_queue_item_id' => $queueItemId,
            'now_playing_track_id' => $state['track_id'],
            'now_playing_context_uri' => $state['context_uri'],
            'now_playing_name' => $state['name'],
            'now_playing_artist' => $state['artist'],
            'now_playing_artists' => $state['artists'] ?? [],
            'now_playing_explicit' => $state['explicit'] ?? false,
            'now_playing_album_art_url' => $state['album_art_url'],
            'now_playing_duration_ms' => $state['duration_ms'],
            'is_playing_fallback' => $isFallbackContext,
            'is_playing' => $state['is_playing'],
            'now_playing_position_ms' => $state['progress_ms'],
            'now_playing_started_at' => $state['is_playing'] ? now()->subMilliseconds($state['progress_ms']) : null,
            'shuffle_enabled' => $state['shuffle_enabled'],
            'smart_shuffle_enabled' => $state['smart_shuffle_enabled'],
            'volume_percent' => $state['volume_percent'] ?? $room->volume_percent,
            'volume_supported' => $state['supports_volume'],
            'repeat_mode' => $state['repeat_mode'],
            'playback_inactive_at' => null,
            'playlist_finished_at' => $this->finishedAt($room, $state, $trackChanged, $finished),
        ]);

        RoomUpdated::broadcastFor($room, 'playback');

        $this->tint($room);
    }

    /** The aurora colour for the art on screen. After the broadcast, so working it out never delays a track change. */
    private function tint(Room $room): void
    {
        try {
            if (app(AlbumTint::class)->ensure($room)) {
                RoomUpdated::broadcastFor($room, 'tint');
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * With repeat off, Spotify doesn't stop on the last track or report
     * "nothing active": it rewinds the playlist to its first track and parks
     * there, paused at the very start. A playing track that turns into a
     * different, paused-at-zero track in the same playlist is that signature.
     * It needs no knowledge of the playlist's order, so it also works for
     * playlists Spotify won't let us read.
     */
    private function playlistJustFinished(Room $room, array $state, bool $trackChanged): bool
    {
        return $trackChanged
            && $room->is_playing
            && ! $state['is_playing']
            && $state['progress_ms'] < self::PARKED_AT_START_MS
            && $state['repeat_mode'] === 'off'
            && Room::isPlayableContext($state['context_uri'])
            && $state['context_uri'] === $room->now_playing_context_uri;
    }

    /** Stays set while the room is still parked there; anything else (playing, another track, moved on) clears it. */
    private function finishedAt(Room $room, array $state, bool $trackChanged, bool $finished): ?Carbon
    {
        if ($finished) {
            return now();
        }

        $stillParked = ! $trackChanged
            && ! $state['is_playing']
            && $state['progress_ms'] < self::PARKED_AT_START_MS;

        return $stillParked ? $room->playlist_finished_at : null;
    }

    /**
     * Spotify returned 204: no *active* device anywhere. That's the signal,
     * deliberately not second-guessed against the device list: Spotify keeps
     * listing a device for a while after it has really gone, which is how a
     * dead track used to stay on screen as "paused". The room is marked
     * inactive instead, which hides that track everywhere (see
     * Room::nowPlayingDetails()) while leaving the track and position stored,
     * so Play can still resume it exactly once a device is back.
     */
    private function markInactive(Room $room): void
    {
        $hasSomethingToHide = $room->is_playing || $room->now_playing_track_id || $room->is_playing_fallback;

        if ($room->playback_inactive_at !== null || ! $hasSomethingToHide) {
            return;
        }

        $room->update([
            'is_playing' => false,
            'playback_inactive_at' => now(),
        ]);

        RoomUpdated::broadcastFor($room, 'playback');
    }
}
