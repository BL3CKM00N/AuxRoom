<?php

namespace App\Livewire\Dashboard\Concerns;

use App\Models\Room;
use App\Models\RoomMember;

/**
 * Per-guest ability checks and the host actions that grant/revoke them:
 * the permission gate every control runs through, plus member admin
 * (kick/approve/deny/exempt) and the room-wide switches (private, queue
 * lock, location enforcement) that sit alongside them.
 */
trait ManagesGuestPermissions
{
    /** Maps the ability keys used throughout the app to their per-guest column. */
    private const ABILITY_COLUMNS = [
        'guests_can_play_pause' => 'can_play_pause',
        'guests_can_skip' => 'can_skip',
        'guests_can_seek' => 'can_seek',
        'guests_can_set_volume' => 'can_set_volume',
        'guests_can_manage_playlist' => 'can_manage_playlist',
        'guests_can_view_activity' => 'can_view_activity',
    ];

    /**
     * Whether the current visitor is allowed to use a given control — used to
     * visually gray out buttons for guests, mirroring the server-side gate.
     * Add-to-queue stays a single room-wide switch (it's what the emergency
     * stop needs: one instant lock for everyone). Everything else is per-guest.
     */
    public function canGuest(string $ability): bool
    {
        if ($this->isHost) {
            return true;
        }

        // Mirrors passesGate()'s location check — controls otherwise looked
        // enabled for an out-of-range guest and only failed silently on click.
        if (! $this->member->passesLocationCheck()) {
            return false;
        }

        if ($ability === 'guests_can_add_to_queue') {
            return (bool) $this->room->guests_can_add_to_queue;
        }

        return (bool) $this->member->{self::ABILITY_COLUMNS[$ability]};
    }

    private function passesGate(string $ability): bool
    {
        $this->controlError = '';

        if (! $this->isApproved) {
            $this->controlError = 'Waiting for the host to let you in.';

            return false;
        }

        if (! $this->isHost && $ability === 'guests_can_add_to_queue' && ! $this->room->guests_can_add_to_queue) {
            $this->controlError = 'The host has locked the queue for everyone.';

            return false;
        }

        if (! $this->isHost && $ability !== 'guests_can_add_to_queue' && ! (bool) $this->member->{self::ABILITY_COLUMNS[$ability]}) {
            $this->controlError = 'The host hasn\'t given you this permission.';

            return false;
        }

        if (! $this->member->passesLocationCheck()) {
            $this->controlError = 'You need to verify you\'re near the room before controlling playback.';

            return false;
        }

        return true;
    }

    public function togglePrivate(): void
    {
        if (! $this->isHost) {
            return;
        }

        $isPrivate = ! $this->room->is_private;
        $this->room->update(['is_private' => $isPrivate]);
        $this->logActivity('settings', $isPrivate
            ? 'Room set to private. New guests now need approval to join.'
            : 'Room set to public. Anyone with the code can join instantly.');
        $this->broadcastUpdate('settings');
    }

    /**
     * Set a single guest's permission for a single ability. Permissions are
     * entirely per-guest — there's no room-wide default to fall back to
     * (except add-to-queue, which the emergency stop can still lock for
     * everyone at once).
     */
    public function setMemberPermission(int $memberId, string $ability, bool $allowed): void
    {
        if (! $this->isHost || ! array_key_exists($ability, self::ABILITY_COLUMNS)) {
            return;
        }

        $target = RoomMember::where('room_id', $this->room->id)->where('id', $memberId)->first();

        if (! $target || $target->isHost()) {
            return;
        }

        $column = self::ABILITY_COLUMNS[$ability];
        $target->update([$column => $allowed]);

        $label = str($ability)->after('guests_can_')->replace('_', ' ')->toString();
        $this->logActivity('permission_changed', "{$target->display_name} was ".($allowed ? 'allowed to ' : 'blocked from ')."{$label}.");
        $this->broadcastUpdate('members');
    }

    /**
     * The big red "stop the bleeding" button — instantly blocks every guest,
     * including ones who join later, from adding to the queue, overriding
     * their individual permission. This is the one deliberate exception to
     * "no room-wide switches": an emergency needs a single, instant lock.
     */
    public function emergencyStopQueue(): void
    {
        if (! $this->isHost) {
            return;
        }

        $this->room->update(['guests_can_add_to_queue' => false]);
        $this->logActivity('settings', 'Host locked the queue. No one can add songs right now.');
        $this->broadcastUpdate('settings');
    }

    public function reopenQueue(): void
    {
        if (! $this->isHost) {
            return;
        }

        $this->room->update(['guests_can_add_to_queue' => true]);
        $this->logActivity('settings', 'Host reopened the queue.');
        $this->broadcastUpdate('settings');
    }

    public function revokeAllAccess(): void
    {
        if (! $this->isHost) {
            return;
        }

        $this->room->members()->where('role', 'guest')->update(['left_at' => now()]);

        do {
            $code = Room::generateInviteCode();
        } while (Room::where('invite_code', $code)->exists());

        $this->room->update(['invite_code' => $code]);
        $this->logActivity('access_revoked', 'Host revoked access for all guests.');
        $this->redirect(route('dashboard'));
    }

    public function grantLocationException(int $memberId): void
    {
        if (! $this->isHost) {
            return;
        }

        $target = RoomMember::where('room_id', $this->room->id)->where('id', $memberId)->first();

        if (! $target) {
            return;
        }

        $target->update(['location_exempt' => true]);
        $this->logActivity('settings', "{$target->display_name} was exempted from the location check.");
        $this->broadcastUpdate('members');
    }

    public function kickMember(int $memberId): void
    {
        if (! $this->isHost) {
            return;
        }

        $target = RoomMember::where('room_id', $this->room->id)->where('id', $memberId)->first();

        if ($target && ! $target->isHost()) {
            $target->update(['left_at' => now()]);
            $this->logActivity('member_kicked', "{$target->display_name} was removed from the room.");
            $this->broadcastUpdate('members');
        }
    }

    public function approveMember(int $memberId): void
    {
        if (! $this->isHost) {
            return;
        }

        $target = RoomMember::where('room_id', $this->room->id)->where('id', $memberId)->first();

        if ($target && ! $target->isHost()) {
            $target->update(['approved_at' => now()]);
            $this->logActivity('member_approved', "{$target->display_name} was let into the room.");
            $this->broadcastUpdate('members');
        }
    }

    public function denyMember(int $memberId): void
    {
        if (! $this->isHost) {
            return;
        }

        $target = RoomMember::where('room_id', $this->room->id)->where('id', $memberId)->first();

        if ($target && ! $target->isHost()) {
            $target->update(['left_at' => now()]);
            $this->logActivity('member_denied', "{$target->display_name}'s request to join was denied.");
            $this->broadcastUpdate('members');
        }
    }
}
