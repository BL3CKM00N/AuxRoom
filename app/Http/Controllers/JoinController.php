<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Services\RoomMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class JoinController extends Controller
{
    public function create(Request $request)
    {
        return view('join', [
            'code' => $request->query('code'),
        ]);
    }

    public function store(Request $request, RoomMembership $membership): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'invite_code' => ['required', 'string'],
        ]);

        $room = Room::where('invite_code', strtoupper(trim($validated['invite_code'])))
            ->whereNull('closed_at')
            ->first();

        if (! $room) {
            throw ValidationException::withMessages([
                'invite_code' => 'That invite code doesn\'t match an open room.',
            ]);
        }

        if ($request->user()) {
            $membership->joinAsAuthenticatedGuest($room, $request->user());

            return redirect()->route('rooms.show', $room);
        }

        ['guest_token' => $token] = $membership->joinAsGuest($room, $validated['name']);

        return redirect()->route('rooms.show', $room)
            ->cookie(RoomMembership::cookieName($room), $token, 60 * 24 * 30);
    }
}
