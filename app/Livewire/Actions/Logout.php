<?php

namespace App\Livewire\Actions;

use App\Events\RoomUpdated;
use App\Models\ActivityEvent;
use App\Services\Auth\UserSessions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class Logout
{
    /**
     * Log this device out. The host's room closes only when this was their last
     * live login: with another device still signed in, the room stays open and
     * the person is told so.
     */
    public function __invoke(): void
    {
        $user = Auth::guard('web')->user();
        $room = $user?->activeHostedRoom();
        $stillSignedIn = $user ? app(UserSessions::class)->otherLiveCount($user, Session::getId()) > 0 : false;

        if ($room && ! $stillSignedIn) {
            $this->closeRoom($room, 'Room closed automatically because the host logged out.');
        }

        $this->end();

        if ($room && $stillSignedIn) {
            Session::flash('status', "Logged out here. Your room stays open because you're still signed in on another device.");
        }
    }

    /**
     * Log out on every device and close the room. Remote devices are told why
     * on their next visit; "Remember me" cookies stop working. Returns how many
     * devices were signed out, this one included.
     */
    public function everywhere(): int
    {
        $user = Auth::guard('web')->user();

        if (! $user) {
            return 0;
        }

        $sessions = app(UserSessions::class);
        $room = $user->activeHostedRoom();

        $count = 1 + $sessions->revokeOthers($user, Session::getId(), 'everywhere');
        $sessions->rotateRememberToken($user);

        if ($room) {
            $this->closeRoom($room, 'Room closed automatically because the host logged out on all devices.');
        }

        $this->end();

        Session::flash('status', ($count > 1 ? "Logged out on all {$count} devices" : 'Logged out').($room ? ' and closed your room.' : '.'));

        return $count;
    }

    private function closeRoom($room, string $message): void
    {
        ActivityEvent::create(['room_id' => $room->id, 'type' => 'closed', 'message' => $message]);

        $room->update(['closed_at' => now()]);
        RoomUpdated::broadcastFor($room, 'closed');
    }

    private function end(): void
    {
        Auth::guard('web')->logout();

        Session::invalidate();
        Session::regenerateToken();
    }
}
