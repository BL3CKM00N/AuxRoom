<?php

namespace App\Services\Spotify;

use App\Models\Room;

/**
 * What is genuinely coming up, from Spotify's raw queue.
 *
 * That queue is not the real remainder. Spotify pads it to 15-20 entries by
 * wrapping around the playlist, even with repeat off and even when the
 * current track is the last one, and it lists the current track itself again
 * in that padding. Shown as-is it looks like a loop that isn't happening.
 *
 * The raw queue is guest-queued tracks first, then the playlist's own
 * remainder, then padding. So: peel off the guest-queued tracks (known from
 * the room's own unplayed queue items), then keep only as much of the rest as
 * really follows the current track.
 */
class UpcomingQueue
{
    public function __construct(private SpotifyClientFactory $clients) {}

    /**
     * @return array<int, array{id: string, uri: ?string, name: string, artist: string, album_art_url: ?string, duration_ms: int, is_queued: bool}>
     */
    public function forRoom(Room $room): array
    {
        if ($this->clients->isMock($room) || ! $room->playbackProvider?->hasSpotifyConnected()) {
            return [];
        }

        // Spotify parks on the first track after the playlist ends; its queue
        // is then the playlist again, which is not "coming up".
        if ($room->playlistFinished()) {
            return [];
        }

        $client = $this->clients->forRoom($room);
        $context = $room->now_playing_context_uri;
        $contextIds = $room->repeat_mode !== 'track' && Room::isPlayableContext($context)
            ? $client->getContextTrackIds($context)
            : null;

        return self::trim(
            $client->getQueue(),
            $room->now_playing_track_id,
            $room->repeat_mode ?: 'off',
            $contextIds,
            $room->queueItems()->whereNull('played_at')->pluck('spotify_track_id')->all(),
        );
    }

    /**
     * @param  array<int, array>  $queue  Spotify's raw queue
     * @param  array<int, string>|null  $contextIds  the playing playlist/album in order, when readable
     * @param  array<int, string>  $queuedIds  track ids guests queued that haven't played yet
     * @return array<int, array>
     */
    public static function trim(array $queue, ?string $currentId, string $repeat, ?array $contextIds, array $queuedIds): array
    {
        $queue = array_values(array_filter($queue, fn (array $t) => ! empty($t['id'])));

        // Guest-queued tracks lead the queue, in the order they were added.
        $pending = array_count_values($queuedIds);
        $queued = [];

        foreach ($queue as $track) {
            if (($pending[$track['id']] ?? 0) < 1) {
                break;
            }

            $pending[$track['id']]--;
            $queued[] = $track + ['is_queued' => true];
        }

        // Repeat-track: the rest is only the current track, over and over.
        if ($repeat === 'track') {
            return $queued;
        }

        $rest = array_slice($queue, count($queued));
        $keep = self::realRemainder($rest, $currentId, $repeat, $contextIds);

        return [
            ...$queued,
            ...array_map(fn (array $t) => $t + ['is_queued' => false], array_slice($rest, 0, $keep)),
        ];
    }

    /** How many of the playlist's own queue entries really come next. */
    private static function realRemainder(array $rest, ?string $currentId, string $repeat, ?array $contextIds): int
    {
        $position = $currentId !== null && $contextIds !== null ? array_search($currentId, $contextIds, true) : false;

        if ($position !== false) {
            // Repeat off: just the tracks after this one. Repeat all: those,
            // then the start of the list again, up to (not including) this track.
            $count = $repeat === 'context'
                ? count($contextIds) - 1
                : count($contextIds) - $position - 1;

            return min($count, count($rest));
        }

        // The order isn't known, but wrapping revisits the current track, so
        // what precedes its next appearance is the real remainder.
        foreach ($rest as $i => $track) {
            if ($track['id'] === $currentId) {
                return $i;
            }
        }

        return count($rest);
    }
}
