<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A lightweight "something changed" ping broadcast on a public per-room
 * channel. Clients react by asking the server to re-render current state,
 * rather than trying to keep a duplicate copy of it in JS.
 */
class RoomUpdated implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(public Room $room, public string $reason = 'update', public ?int $seq = null) {}

    /**
     * ShouldBroadcastNow means this goes out inline with the request, not
     * queued — worth it for real-time feel, but it means a broadcaster
     * outage (Reverb down, network hiccup) would otherwise throw and fail
     * the whole request, even though whatever the caller just did (create a
     * room, join, etc.) already succeeded. Guests still catch up via the
     * regular polling fallback, so a missed push is fine; a failed room
     * creation because of it is not.
     */
    public static function broadcastFor(Room $room, string $reason = 'update'): void
    {
        try {
            broadcast(new self($room, $reason, self::nextSequence($room)));
        } catch (Throwable $e) {
            Log::warning('Failed to broadcast RoomUpdated', [
                'room_id' => $room->id,
                'reason' => $reason,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Every push of a room carries the next number. A screen that sees a number missing knows a push was lost
     * on the way and re-reads the room, instead of staying wrong until the next scheduled check.
     */
    private static function nextSequence(Room $room): ?int
    {
        try {
            $key = "room:seq:{$room->id}";
            Cache::add($key, 0, 86400);
            $next = Cache::increment($key);

            return is_int($next) ? $next : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function broadcastOn(): array
    {
        return [new Channel('room.'.$this->room->invite_code)];
    }

    public function broadcastAs(): string
    {
        return 'RoomUpdated';
    }

    public function broadcastWith(): array
    {
        return ['reason' => $this->reason, 'seq' => $this->seq];
    }
}
