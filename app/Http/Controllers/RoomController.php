<?php

namespace App\Http\Controllers;

use App\Models\ActivityEvent;
use App\Models\Room;
use App\Services\RoomMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function create(Request $request)
    {
        if ($request->user()->activeHostedRoom()) {
            return redirect()->route('dashboard')
                ->with('status', "You're already hosting a room. Close it before creating a new one.");
        }

        return view('dashboard.create', [
            'hasSpotify' => $request->user()->hasSpotifyConnected(),
            'account' => $request->user()->spotifyAccount,
        ]);
    }

    public function store(Request $request, RoomMembership $membership): RedirectResponse
    {
        if ($request->user()->activeHostedRoom()) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'is_private' => ['nullable', 'boolean'],
            'location_enforced' => ['nullable', 'boolean'],
            'location_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'location_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'location_radius_m' => ['nullable', 'integer', 'min:10', 'max:5000'],
        ]);

        do {
            $code = Room::generateInviteCode();
        } while (Room::where('invite_code', $code)->exists());

        $room = Room::create([
            'invite_code' => $code,
            'host_id' => $request->user()->id,
            'playback_provider_id' => $request->user()->id,
            'is_private' => $request->boolean('is_private', true),
            'location_enforced' => $request->boolean('location_enforced', false),
            'location_lat' => $validated['location_lat'] ?? null,
            'location_lng' => $validated['location_lng'] ?? null,
            'location_radius_m' => $validated['location_radius_m'] ?? null,
        ]);

        $membership->joinAsHost($room);

        ActivityEvent::create([
            'room_id' => $room->id,
            'type' => 'room_created',
            'message' => 'Room created.',
        ]);

        return redirect()->route('dashboard');
    }

    public function destroy(Request $request, Room $room): RedirectResponse
    {
        abort_unless($room->host_id === $request->user()->id, 403);

        $room->update(['closed_at' => now()]);

        return redirect()->route('dashboard')->with('status', 'Room closed.');
    }
}
