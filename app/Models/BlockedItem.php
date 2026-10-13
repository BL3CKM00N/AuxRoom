<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockedItem extends Model
{
    protected $fillable = ['room_id', 'kind', 'spotify_id', 'name', 'detail'];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
