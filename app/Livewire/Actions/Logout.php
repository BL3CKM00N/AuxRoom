<?php

namespace App\Livewire\Actions;

use App\Events\RoomUpdated;
use App\Models\ActivityEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class Logout
{
    /**
     * Log the current user out of the application.
     */
    public function __invoke(): void
    {
        if ($room = Auth::guard('web')->user()?->activeHostedRoom()) {
            ActivityEvent::create([
                'room_id' => $room->id,
                'type' => 'closed',
                'message' => 'Room closed automatically because the host logged out.',
            ]);

            $room->update(['closed_at' => now()]);
            broadcast(new RoomUpdated($room, 'closed'));
        }

        Auth::guard('web')->logout();

        Session::invalidate();
        Session::regenerateToken();
    }
}
