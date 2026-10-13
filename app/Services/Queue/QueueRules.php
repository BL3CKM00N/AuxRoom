<?php

namespace App\Services\Queue;

use App\Models\PlayedTrack;
use App\Models\QueueItem;
use App\Models\Room;
use App\Models\RoomMember;

/**
 * The room's rules for adding a song: the host's block list, the explicit filter, the duplicate
 * guard and the limit per guest. Checked on the server when a song is added, so a tampered page
 * can't skip them. Staff (the host and co-hosts) get a warning they can override instead of a
 * refusal; a guest the host exempted is not checked at all (see applies()).
 */
class QueueRules
{
    /**
     * The first rule this song breaks, or null when it may be added.
     *
     * @param  array{id: string, name?: string, artist?: string, artists?: array<int, array{id: string, name: string}>, explicit?: bool}  $track
     * @return array{code: string, message: string}|null
     */
    public function violation(Room $room, RoomMember $member, array $track): ?array
    {
        if (! $this->applies($member)) {
            return null;
        }

        return $this->blocked($room, $track)
            ?? $this->explicit($room, $track)
            ?? $this->duplicate($room, $track)
            ?? $this->limit($room, $member);
    }

    /** Whether the rules are checked for this person at all. Staff are checked too, so they can be warned. */
    public function applies(RoomMember $member): bool
    {
        return ! $member->queue_rules_exempt;
    }

    /** @return array{code: string, message: string}|null */
    private function blocked(Room $room, array $track): ?array
    {
        $ids = $room->blockedItems()->get(['kind', 'spotify_id', 'name']);

        if ($ids->isEmpty()) {
            return null;
        }

        if ($ids->where('kind', 'track')->contains('spotify_id', $track['id'])) {
            return ['code' => 'QUEUE-BLOCKED', 'message' => 'The host has blocked that song in this room.'];
        }

        $artist = $ids->where('kind', 'artist')->first(fn ($item) => in_array($item->spotify_id, array_column($track['artists'] ?? [], 'id'), true));

        if ($artist) {
            return ['code' => 'QUEUE-BLOCKED', 'message' => "The host has blocked songs by {$artist->name} in this room."];
        }

        return null;
    }

    /** @return array{code: string, message: string}|null */
    private function explicit(Room $room, array $track): ?array
    {
        if ($room->block_explicit && ! empty($track['explicit'])) {
            return ['code' => 'QUEUE-EXPLICIT', 'message' => 'This room does not allow explicit songs.'];
        }

        return null;
    }

    /** @return array{code: string, message: string}|null */
    private function duplicate(Room $room, array $track): ?array
    {
        $window = $room->duplicate_guard_tracks;

        if (! $window) {
            return null;
        }

        $recent = PlayedTrack::where('room_id', $room->id)->orderByDesc('id')->limit($window)->pluck('spotify_track_id');

        $waiting = QueueItem::where('room_id', $room->id)->whereNull('played_at')->pluck('spotify_track_id');

        $taken = $recent->merge($waiting)->push($room->now_playing_track_id)->filter();

        if ($taken->contains($track['id'])) {
            return ['code' => 'QUEUE-DUPLICATE', 'message' => 'That song is already queued or played a moment ago.'];
        }

        return null;
    }

    /** @return array{code: string, message: string}|null */
    private function limit(Room $room, RoomMember $member): ?array
    {
        $limit = $room->queue_limit_per_guest;

        if (! $limit) {
            return null;
        }

        $waiting = QueueItem::where('room_id', $room->id)->where('added_by_id', $member->id)->whereNull('played_at')->count();

        if ($waiting >= $limit) {
            $songs = $limit === 1 ? '1 song' : "{$limit} songs";

            return ['code' => 'QUEUE-LIMIT', 'message' => "You already have {$songs} waiting. You can add another once one of yours plays."];
        }

        return null;
    }
}
