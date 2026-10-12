<?php

namespace App\Services\Spotify;

use App\Models\Room;

/**
 * Shows the next track at once after a skip, before Spotify has been asked what is
 * playing. The next track is the first entry of the queue the room is already showing
 * (guest-queued songs first, then the playlist's own order), which is exactly what a
 * skip plays unless shuffle is on or the track repeats. The watcher confirms within a
 * second and corrects the room if Spotify turns out to be playing something else.
 */
class PlaybackPrediction
{
    /** Whether a skip's result can be predicted at all. */
    public static function possible(Room $room): bool
    {
        return ! $room->shuffle_enabled && $room->repeat_mode !== 'track' && ! $room->playlistFinished();
    }

    /** @param object{spotify_track_id: string, name: string, artist: string, album_art_url: ?string, duration_ms: int} $next */
    public function apply(Room $room, object $next): void
    {
        // A song a guest queued is used up the moment it plays; the same bookkeeping PlaybackSync does.
        $matched = $room->queueItems()->where('spotify_track_id', $next->spotify_track_id)->whereNull('played_at')->first();
        $matched?->update(['played_at' => now()]);

        $room->update([
            'now_playing_queue_item_id' => $matched?->id,
            'now_playing_track_id' => $next->spotify_track_id,
            'now_playing_name' => $next->name,
            'now_playing_artist' => $next->artist,
            'now_playing_album_art_url' => $next->album_art_url,
            'now_playing_duration_ms' => $next->duration_ms,
            'now_playing_position_ms' => 0,
            'now_playing_started_at' => now(),
            'is_playing' => true,
        ]);
    }
}
