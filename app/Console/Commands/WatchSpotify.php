<?php

namespace App\Console\Commands;

use App\Models\Room;
use App\Services\Spotify\RoomWatcher;
use App\Services\Spotify\WatchState;
use Illuminate\Console\Command;

class WatchSpotify extends Command
{
    protected $signature = 'spotify:watch
        {--once : Run a single pass over the rooms and stop}
        {--max-seconds=3600 : Exit after this long, so the supervising loop starts a fresh process (guards against slow leaks)}';

    protected $description = 'Polls Spotify for every open room that someone is watching, so screens show what the server knows (see RoomWatcher).';

    public function handle(RoomWatcher $watcher): int
    {
        $started = microtime(true);
        $rooms = collect();
        $roomsLoadedAt = 0.0;
        $due = [];
        $once = (bool) $this->option('once');
        $maxSeconds = (float) $this->option('max-seconds');

        $this->components->info('Spotify watcher started.');

        do {
            WatchState::beat();

            // The set of open rooms changes slowly; looking every few seconds is plenty.
            if ($once || microtime(true) - $roomsLoadedAt >= 5) {
                $rooms = Room::whereNull('closed_at')->with('playbackProvider.spotifyAccount')->get()->filter(fn (Room $r) => $watcher->watchable($r))->values();
                $roomsLoadedAt = microtime(true);
            }

            $ready = [];

            foreach ($rooms as $room) {
                if (! $once && ! $watcher->isDue($room, $due[$room->id] ?? null)) {
                    continue;
                }

                // Nobody is looking: check again in a second whether someone started.
                if ($watcher->cadence($room) === null && ! $once) {
                    $due[$room->id] = microtime(true) + 1;

                    continue;
                }

                $ready[] = $room;
            }

            // The same room should not always be first in line when Spotify's allowance runs short.
            shuffle($ready);

            // Rooms that are due together are asked at the same time, so one slow answer does not hold up the rest.
            $answers = $watcher->prefetch(collect($ready));

            foreach ($ready as $room) {
                try {
                    $next = $watcher->tick($room, $answers[$room->id] ?? null);
                } catch (\Throwable $e) {
                    report($e);
                    $next = 5.0;
                }

                $due[$room->id] = microtime(true) + ($next ?? 1.0);
            }

            if ($once) {
                break;
            }

            usleep(100_000);
        } while (microtime(true) - $started < $maxSeconds);

        return self::SUCCESS;
    }
}
