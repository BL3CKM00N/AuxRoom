<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayedTrack extends Model
{
    public $timestamps = false;

    protected $fillable = ['room_id', 'spotify_track_id', 'played_at'];

    /** Keeps the table small; the duplicate guard never looks further back than this. */
    private const KEEP = 200;

    /** Records a track as played in the room, unless it is the one recorded last (a skip prediction and the sync both report the same change). */
    public static function record(Room $room, ?string $trackId): void
    {
        if (! $trackId) {
            return;
        }

        $last = static::where('room_id', $room->id)->latest('id')->value('spotify_track_id');

        if ($last === $trackId) {
            return;
        }

        static::create(['room_id' => $room->id, 'spotify_track_id' => $trackId, 'played_at' => now()]);

        $cutoff = static::where('room_id', $room->id)->orderByDesc('id')->skip(self::KEEP)->value('id');

        if ($cutoff) {
            static::where('room_id', $room->id)->where('id', '<=', $cutoff)->delete();
        }
    }

    protected function casts(): array
    {
        return ['played_at' => 'datetime'];
    }
}
