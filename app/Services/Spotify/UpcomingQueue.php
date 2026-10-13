<?php

namespace App\Services\Spotify;

use App\Models\Room;
use App\Services\Queue\QueueFeeder;

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
    public function __construct(private SpotifyClientFactory $clients, private QueueFeeder $feeder) {}

    /**
     * @return array<int, array{id: string, uri: ?string, name: string, artist: string, album_art_url: ?string, duration_ms: int, is_queued: bool}>
     */
    public function forRoom(Room $room): array
    {
        if ($this->clients->isMock($room) || ! $room->playbackProvider?->hasSpotifyConnected()) {
            return $this->pendingQueueItems($room);
        }

        // Spotify parks on the first track after the playlist ends; its queue
        // is then the playlist again, which is not "coming up".
        if ($room->playlistFinished()) {
            return [];
        }

        $client = $this->clients->forRoom($room);
        $context = $room->now_playing_context_uri;

        // With the watcher running, the queue and playlist order come from what it last read, so
        // rendering a page never waits on Spotify. The first render of a room, before the watcher
        // has looked, asks once and stores the answer.
        $watched = WatchState::watcherAlive();

        $queue = $watched
            ? (WatchState::queue($room) ?? tap($client->getQueue(), fn ($q) => WatchState::putQueue($room, $q)))
            : $client->getQueue();

        $contextIds = $room->repeat_mode !== 'track' && Room::isPlayableContext($context)
            ? ($watched ? ($client->peekContextTrackIds($context) ?? $client->getContextTrackIds($context)) : $client->getContextTrackIds($context))
            : null;

        // Only songs already handed to Spotify are in its queue; the rest wait on AuxRoom's list.
        $inSpotify = $room->queueItems()->released()->whereNull('played_at')->orderBy('released_at')->orderBy('id')->get();

        return $this->withQueueItems($room, self::trim(
            $queue,
            $room->now_playing_track_id,
            $room->repeat_mode ?: 'off',
            $contextIds,
            $inSpotify->pluck('spotify_track_id')->all(),
        ), $inSpotify);
    }

    /**
     * Ties the guests' songs to what Spotify reports: each queued entry knows which song of the room it is
     * (so it can be removed and credited), songs removed while already in Spotify's queue are hidden, a song
     * just handed over that Spotify's answer does not list yet still shows, and the waiting list follows the
     * queued songs, ahead of the playlist's own tracks.
     *
     * @param  array<int, array>  $trimmed
     * @param  \Illuminate\Support\Collection<int, \App\Models\QueueItem>  $inSpotify
     * @return array<int, array>
     */
    private function withQueueItems(Room $room, array $trimmed, $inSpotify): array
    {
        $unmatched = $inSpotify->values();
        $queued = [];
        $rest = [];

        foreach ($trimmed as $track) {
            if (! $track['is_queued']) {
                $rest[] = $track + ['item_id' => null, 'added_by_id' => null, 'waiting' => false, 'locked' => false];

                continue;
            }

            $index = $unmatched->search(fn ($item) => $item->spotify_track_id === $track['id']);
            $item = $index === false ? null : $unmatched->pull($index);

            // Removed while in Spotify's queue: still there until it starts (then it is skipped), but not shown.
            if ($item?->removed_at) {
                continue;
            }

            $queued[] = $track + ['item_id' => $item?->id, 'added_by_id' => $item?->added_by_id, 'waiting' => false, 'locked' => true];
        }

        // Handed over a moment ago, and Spotify's answer is older than that.
        foreach ($unmatched->whereNull('removed_at') as $item) {
            $queued[] = $this->entry($item, waiting: false);
        }

        $waiting = $room->waiting_list_enabled
            ? $this->feeder->waiting($room)->map(fn ($item) => $this->entry($item, waiting: true))->all()
            : [];

        return [...$queued, ...$waiting, ...$rest];
    }

    /** @return array<string, mixed> */
    private function entry($item, bool $waiting): array
    {
        return [
            'id' => $item->spotify_track_id,
            'uri' => 'spotify:track:'.$item->spotify_track_id,
            'name' => $item->name,
            'artist' => $item->artist,
            'album_art_url' => $item->album_art_url,
            'duration_ms' => (int) $item->duration_ms,
            'is_queued' => true,
            'item_id' => $item->id,
            'added_by_id' => $item->added_by_id,
            'waiting' => $waiting,
            'locked' => ! $waiting,
        ];
    }

    /**
     * Without Spotify there is no queue to read, so the room's own songs that
     * haven't played yet are the queue (what guests added, in order).
     *
     * @return array<int, array>
     */
    private function pendingQueueItems(Room $room): array
    {
        return $room->pendingQueueItems()->get()->map(fn ($item) => [
            'id' => $item->spotify_track_id,
            'uri' => 'spotify:track:'.$item->spotify_track_id,
            'name' => $item->name,
            'artist' => $item->artist,
            'album_art_url' => $item->album_art_url,
            'duration_ms' => (int) $item->duration_ms,
            'is_queued' => true,
        ])->all();
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
