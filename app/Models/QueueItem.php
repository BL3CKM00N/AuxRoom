<?php

namespace App\Models;

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
    ];

    protected function casts(): array
    {
        return [
            'played_at' => 'datetime',
        ];
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
