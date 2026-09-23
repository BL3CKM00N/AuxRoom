<?php

namespace App\Services;

use App\Events\RoomUpdated;
use App\Models\ActivityEvent;
use App\Models\Room;
use App\Models\RoomMember;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoomMembership
{
    public const COOKIE_PREFIX = 'auxroom_guest_';

    /**
     * Resolve the current visitor's membership in a room, whether they're
     * logged in or joined as a guest via a signed cookie token.
     */
    public function resolve(Room $room, Request $request): ?RoomMember
    {
        if ($request->user()) {
            $member = $room->members()
                ->where('user_id', $request->user()->id)
                ->whereNull('left_at')
                ->first();

            if ($member) {
                return $member;
            }
        }

        $guestToken = $request->cookie(self::cookieName($room));

        if (! $guestToken) {
            return null;
        }

        return $room->members()
            ->where('guest_token', $guestToken)
            ->whereNull('left_at')
            ->first();
    }

    public static function cookieName(Room $room): string
    {
        return self::COOKIE_PREFIX.$room->id;
    }

    public function joinAsHost(Room $room): RoomMember
    {
        return $this->recordJoin($room, [
            'user_id' => $room->host_id,
            'display_name' => $room->host->name,
            'role' => 'host',
            'approved_at' => now(),
        ]);
    }

    public function joinAsAuthenticatedGuest(Room $room, $user): RoomMember
    {
        return $this->recordJoin($room, [
            'user_id' => $user->id,
            'display_name' => $user->name,
            'role' => 'guest',
            'approved_at' => $room->is_private ? null : now(),
        ]);
    }

    /**
     * @return array{member: RoomMember, guest_token: string}
     */
    public function joinAsGuest(Room $room, string $name): array
    {
        $token = Str::random(48);

        $member = $this->recordJoin($room, [
            'guest_token' => $token,
            'display_name' => $name,
            'role' => 'guest',
            'approved_at' => $room->is_private ? null : now(),
        ]);

        return ['member' => $member, 'guest_token' => $token];
    }

    private function recordJoin(Room $room, array $attributes): RoomMember
    {
        $existing = null;

        if (! empty($attributes['user_id'])) {
            $existing = $room->members()->where('user_id', $attributes['user_id'])->first();
        }

        if ($existing) {
            // Returning members keep whatever approval status they already had —
            // only a brand new guest is subject to the room's current privacy setting.
            $existing->update(['left_at' => null, 'last_seen_at' => now()]);
            $member = $existing;
        } else {
            $member = $room->members()->create([
                ...$attributes,
                'last_seen_at' => now(),
            ]);
        }

        ActivityEvent::create([
            'room_id' => $room->id,
            'member_id' => $member->id,
            'type' => 'member_joined',
            'message' => "{$member->display_name} joined the room.",
        ]);

        RoomUpdated::broadcastFor($room, 'members');

        return $member;
    }

    public function leave(RoomMember $member): void
    {
        $member->update(['left_at' => now()]);

        ActivityEvent::create([
            'room_id' => $member->room_id,
            'member_id' => $member->id,
            'type' => 'member_left',
            'message' => "{$member->display_name} left the room.",
        ]);

        RoomUpdated::broadcastFor($member->room, 'members');
    }
}
