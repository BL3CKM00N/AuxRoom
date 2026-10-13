<?php

namespace App\Livewire\Dashboard\Concerns;

use App\Models\RoomMember;
use App\Services\Queue\QueueFeeder;

/**
 * The host's side of the queue rules (see QueueRules): the limit per guest, the duplicate guard,
 * the explicit filter, the block list, and which guests are exempt. Host only: a co-host can
 * add past the rules but not change them.
 */
trait ManagesQueueRules
{
    public const LIMIT_CHOICES = [1, 2, 3, 5, 10];

    public const DUPLICATE_CHOICES = [5, 10, 20, 50];

    /** A block list this long is a sign something is off, and it is shown in full. */
    private const MAX_BLOCKED = 200;

    public function setQueueLimit(?int $limit): void
    {
        if (! $this->isHost || ($limit !== null && ! in_array($limit, self::LIMIT_CHOICES, true))) {
            return;
        }

        $this->room->update(['queue_limit_per_guest' => $limit]);
        $this->logActivity('settings', $limit ? "Host limited guests to {$limit} waiting ".($limit === 1 ? 'song.' : 'songs.') : 'Host removed the limit on waiting songs.');
        $this->broadcastUpdate('settings');
    }

    public function setDuplicateGuard(?int $tracks): void
    {
        if (! $this->isHost || ($tracks !== null && ! in_array($tracks, self::DUPLICATE_CHOICES, true))) {
            return;
        }

        $this->room->update(['duplicate_guard_tracks' => $tracks]);
        $this->logActivity('settings', $tracks ? "Host turned on the duplicate guard for the last {$tracks} tracks." : 'Host turned off the duplicate guard.');
        $this->broadcastUpdate('settings');
    }

    /**
     * Songs wait on AuxRoom's list and go to Spotify one at a time (see QueueFeeder). Switching it off hands
     * everything waiting to Spotify at once, so nothing is lost.
     */
    public function toggleWaitingList(): void
    {
        if (! $this->isHost) {
            return;
        }

        $on = ! $this->room->waiting_list_enabled;
        $this->room->update(['waiting_list_enabled' => $on]);

        if ($on) {
            $this->logActivity('settings', 'Host turned on the waiting list. Songs now wait here and go to Spotify one at a time.');
        } else {
            $moved = app(QueueFeeder::class)->flush($this->room);
            $this->logActivity('settings', 'Host turned off the waiting list.'.($moved ? " {$moved} waiting ".($moved === 1 ? 'song was' : 'songs were').' sent to Spotify.' : ''));
        }

        $this->broadcastUpdate('queue');
    }

    /** Waiting songs go out one per guest in turn. Only does something while the waiting list is on. */
    public function toggleRotation(): void
    {
        if (! $this->isHost || ! $this->room->waiting_list_enabled) {
            return;
        }

        $on = ! $this->room->rotation_enabled;
        $this->room->update(['rotation_enabled' => $on]);
        $this->logActivity('settings', $on ? 'Host turned on rotation. Guests take turns.' : 'Host turned off rotation.');
        $this->broadcastUpdate('queue');
    }

    public function toggleBlockExplicit(): void
    {
        if (! $this->isHost) {
            return;
        }

        $on = ! $this->room->block_explicit;
        $this->room->update(['block_explicit' => $on]);
        $this->logActivity('settings', $on ? 'Host blocked explicit songs.' : 'Host allowed explicit songs.');
        $this->broadcastUpdate('settings');
    }

    public function blockNowPlayingTrack(): void
    {
        if (! $this->isHost || ! $this->room->now_playing_track_id) {
            return;
        }

        $this->addBlock('track', $this->room->now_playing_track_id, (string) $this->room->now_playing_name, $this->room->now_playing_artist);
    }

    public function blockNowPlayingArtist(string $artistId): void
    {
        if (! $this->isHost) {
            return;
        }

        $artist = collect($this->room->now_playing_artists ?? [])->firstWhere('id', $artistId);

        if ($artist) {
            $this->addBlock('artist', $artist['id'], $artist['name']);
        }
    }

    public function unblock(int $id): void
    {
        if (! $this->isHost) {
            return;
        }

        $item = $this->room->blockedItems()->whereKey($id)->first();

        if ($item) {
            $item->delete();
            $this->logActivity('settings', "Host unblocked \"{$item->name}\".");
            $this->broadcastUpdate('settings');
        }
    }

    public function setQueueRulesExempt(int $memberId, bool $exempt): void
    {
        if (! $this->isHost) {
            return;
        }

        $target = RoomMember::where('room_id', $this->room->id)->whereKey($memberId)->first();

        if (! $target || $target->isHost()) {
            return;
        }

        $target->update(['queue_rules_exempt' => $exempt]);
        $this->logActivity('permission_changed', "{$target->display_name} ".($exempt ? 'no longer follows' : 'now follows')." the queue rules.");
        $this->broadcastUpdate('members');
    }

    private function addBlock(string $kind, string $spotifyId, string $name, ?string $detail = null): void
    {
        if ($this->room->blockedItems()->count() >= self::MAX_BLOCKED) {
            return;
        }

        $item = $this->room->blockedItems()->firstOrCreate(
            ['kind' => $kind, 'spotify_id' => $spotifyId],
            ['name' => $name, 'detail' => $detail],
        );

        if ($item->wasRecentlyCreated) {
            $this->logActivity('settings', $kind === 'artist' ? "Host blocked songs by {$name}." : "Host blocked \"{$name}\".");
            $this->broadcastUpdate('settings');
        }
    }
}
