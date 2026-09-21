<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * A lightweight "something changed" ping broadcast on a public per-room
 * channel. Clients react by asking the server to re-render current state,
 * rather than trying to keep a duplicate copy of it in JS.
 */
class RoomUpdated implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(public Room $room, public string $reason = 'update') {}

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
        return ['reason' => $this->reason];
    }
}
