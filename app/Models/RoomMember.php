<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomMember extends Model
{
    /**
     * How long a location verification stays valid before the guest must be
     * re-checked. Paired with the client's ~30s recheck interval so a couple
     * of missed beats (backgrounded tab, brief signal loss) don't revoke
     * access, but someone who genuinely stops checking in eventually does.
     */
    private const LOCATION_FRESHNESS_SECONDS = 90;

    protected $fillable = [
        'room_id',
        'user_id',
        'guest_token',
        'display_name',
        'role',
        'approved_at',
        'can_play_pause',
        'can_skip',
        'can_seek',
        'can_set_volume',
        'can_manage_playlist',
        'can_view_activity',
        'location_verified_at',
        'location_exempt',
        'last_seen_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'can_play_pause' => 'boolean',
            'can_skip' => 'boolean',
            'can_seek' => 'boolean',
            'can_set_volume' => 'boolean',
            'can_manage_playlist' => 'boolean',
            'can_view_activity' => 'boolean',
            'location_verified_at' => 'datetime',
            'location_exempt' => 'boolean',
            'last_seen_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function queueItemsAdded(): HasMany
    {
        return $this->hasMany(QueueItem::class, 'added_by_id');
    }

    public function isHost(): bool
    {
        return $this->role === 'host';
    }

    public function isApproved(): bool
    {
        return $this->isHost() || $this->approved_at !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isHost() && $this->approved_at === null && $this->left_at === null;
    }

    public function isOnline(): bool
    {
        return $this->left_at === null && $this->last_seen_at?->gt(now()->subMinutes(2));
    }

    public function passesLocationCheck(): bool
    {
        $room = $this->room;

        if (! $room->location_enforced || ! $room->hasLocationBoundary()) {
            return true;
        }

        if ($this->isHost() || $this->location_exempt) {
            return true;
        }

        return $this->location_verified_at !== null
            && $this->location_verified_at->gt(now()->subSeconds(self::LOCATION_FRESHNESS_SECONDS));
    }
}
