<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueItem extends Model
{
    protected $fillable = [
        'room_id',
        'added_by_id',
        'spotify_track_id',
        'name',
        'artist',
        'album_art_url',
        'duration_ms',
        'position',
        'played_at',
        'released_at',
        'removed_at',
        'skipped_at',
        'artists',
        'explicit',
    ];

    protected function casts(): array
    {
        return [
            'played_at' => 'datetime',
            'released_at' => 'datetime',
            'removed_at' => 'datetime',
            'skipped_at' => 'datetime',
            'artists' => 'array',
            'explicit' => 'boolean',
        ];
    }

    /** In Spotify's own queue (or already played), as opposed to waiting on AuxRoom's list. */
    public function scopeReleased(Builder $query): Builder
    {
        return $query->whereNotNull('released_at');
    }

    /** On AuxRoom's waiting list: not handed to Spotify yet, and not removed. */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->whereNull('released_at')->whereNull('removed_at')->whereNull('played_at');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(RoomMember::class, 'added_by_id');
    }
}
