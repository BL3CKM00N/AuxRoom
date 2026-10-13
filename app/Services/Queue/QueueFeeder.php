<?php

namespace App\Services\Queue;

use App\Events\RoomUpdated;
use App\Models\QueueItem;
use App\Models\Room;
use App\Services\Spotify\SpotifyClientFactory;
use App\Services\Spotify\WatchState;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The waiting list (see Room::$waiting_list_enabled). Spotify's queue cannot be reordered, and
 * nothing can be taken out of it, so guests' songs wait here instead and are handed to Spotify
 * one at a time: exactly one real song is always in Spotify's queue ahead ("next up", locked),
 * and the rest can still be removed or, with rotation, taken in turns between guests.
 */
class QueueFeeder
{
    /** Songs handed over when the waiting list is switched off, so none are lost. */
    private const FLUSH_LIMIT = 25;

    public function __construct(private SpotifyClientFactory $clients) {}

    /**
     * The waiting songs in the order they will be handed over. FIFO, or with rotation one song per guest in
     * turn: whoever's last song went out longest ago goes first, so a latecomer is not stuck behind a long run.
     *
     * @return Collection<int, QueueItem>
     */
    public function waiting(Room $room): Collection
    {
        $waiting = $room->queueItems()->waiting()->orderBy('position')->get();

        if (! $room->rotation_enabled || $waiting->count() < 2) {
            return $waiting->values();
        }

        $lastTurn = $room->queueItems()->whereNotNull('released_at')->whereNotNull('added_by_id')
            ->selectRaw('added_by_id, max(released_at) as last')->groupBy('added_by_id')->pluck('last', 'added_by_id')
            ->map(fn ($time) => strtotime((string) $time));

        $byGuest = $waiting->groupBy(fn (QueueItem $item) => $item->added_by_id ?? 0)->map(fn (Collection $items) => $items->values());
        $ordered = collect();
        $turn = 0;

        while ($byGuest->isNotEmpty()) {
            // The guest who has waited longest; the earliest waiting song breaks a tie.
            $guest = $byGuest->sortBy(fn (Collection $items, $id) => [$lastTurn[$id] ?? 0, $items->first()->position])->keys()->first();

            $ordered->push($byGuest[$guest]->shift());
            $lastTurn[$guest] = PHP_INT_MAX - 1000 + $turn++;

            if ($byGuest[$guest]->isEmpty()) {
                $byGuest->forget($guest);
            }
        }

        return $ordered;
    }

    public function next(Room $room): ?QueueItem
    {
        return $this->waiting($room)->first();
    }

    /** Does Spotify already have a real song of ours coming up (not one that was removed and will be skipped)? */
    public function hasNextUp(Room $room): bool
    {
        return $room->queueItems()->released()->whereNull('played_at')->whereNull('removed_at')->exists();
    }

    /** Hands the next waiting song to Spotify when none is waiting there. True when one was handed over. */
    public function feed(Room $room): bool
    {
        if (! $room->waiting_list_enabled || $this->hasNextUp($room) || $room->playbackInactive() || $this->clients->isMock($room)) {
            return false;
        }

        $item = $this->next($room);

        return $item !== null && $this->release($room, $item);
    }

    /**
     * Everything that has to follow a read of Spotify's state, in the watcher's loop: a song removed
     * after it was handed over is skipped the moment it starts, a playlist song that took over right
     * after somebody skipped in the Spotify app (nothing was queued yet) is skipped for the waiting
     * song, and otherwise the next song is handed over if Spotify has none queued.
     */
    public function afterSync(Room $room): void
    {
        $playing = $room->now_playing_queue_item_id ? QueueItem::find($room->now_playing_queue_item_id) : null;

        if ($playing && $playing->removed_at && ! $playing->skipped_at && $room->is_playing) {
            $playing->update(['skipped_at' => now()]);
            $this->feed($room);
            $this->skip($room, 'removed song');

            return;
        }

        if ($this->shouldTakeOver($room)) {
            $this->feed($room);

            if ($this->hasNextUp($room)) {
                $this->skip($room, 'playlist song after a skip');

                return;
            }
        }

        $this->feed($room);
    }

    /** A song of the playlist started right after a skip made elsewhere, while guests' songs were waiting. */
    private function shouldTakeOver(Room $room): bool
    {
        $change = Cache::get("track-change:{$room->id}");

        return $room->waiting_list_enabled
            && $change
            && $change['early']
            && $change['track'] === $room->now_playing_track_id
            && $room->now_playing_queue_item_id === null
            && $room->is_playing
            && ! $room->commandedRecently()
            && ! $this->hasNextUp($room)
            && $room->queueItems()->waiting()->exists()
            && Cache::add("feed:takeover:{$room->id}:{$change['track']}", 1, 60);
    }

    private function skip(Room $room, string $why): void
    {
        $client = $this->clients->forRoom($room);

        try {
            $skipped = $client->skipToNext($this->deviceId($room));
        } catch (Throwable) {
            $skipped = false;
        }

        if (! $skipped) {
            return;
        }

        $from = $room->now_playing_track_id;
        $room->update(['last_local_command_at' => now()]);
        WatchState::expect($room, 'skip', $from);
        RoomUpdated::broadcastFor($room, 'playback');
    }

    /** Switching the waiting list off: everything waiting goes to Spotify now, in the order it would have. */
    public function flush(Room $room): int
    {
        $count = 0;

        foreach ($this->waiting($room)->take(self::FLUSH_LIMIT) as $item) {
            if (! $this->release($room, $item, force: true)) {
                break;
            }

            $count++;
        }

        return $count;
    }

    public function release(Room $room, QueueItem $item, bool $force = false): bool
    {
        // A failing Spotify is not asked again every second.
        if (! $force && Cache::has("feed:cooldown:{$room->id}")) {
            return false;
        }

        $client = $this->clients->forRoom($room);

        try {
            $added = $client->addToPlaybackQueue('spotify:track:'.$item->spotify_track_id, $this->deviceId($room));
        } catch (Throwable) {
            $added = false;
        }

        if (! $added) {
            Cache::put("feed:cooldown:{$room->id}", 1, 5);

            return false;
        }

        $item->update(['released_at' => now()]);

        // Spotify's own queue changed, so what the watcher last stored is out of date.
        Cache::forget("watch:queue:{$room->id}");
        RoomUpdated::broadcastFor($room, 'queue');

        return true;
    }

    private function deviceId(Room $room): ?string
    {
        $active = collect(WatchState::devices($room) ?? [])->firstWhere('is_active', true);

        return $active['id'] ?? $room->playbackProvider?->spotifyAccount?->active_device_id;
    }
}
