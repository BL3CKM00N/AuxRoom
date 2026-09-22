<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Room extends Model
{
    protected $fillable = [
        'invite_code',
        'host_id',
        'playback_provider_id',
        'is_private',
        'guests_can_add_to_queue',
        'fallback_playlist_uri',
        'fallback_playlist_name',
        'fallback_playlist_image_url',
        'is_playing_fallback',
        'location_lat',
        'location_lng',
        'location_radius_m',
        'location_enforced',
        'closed_at',
        'now_playing_queue_item_id',
        'now_playing_track_id',
        'now_playing_name',
        'now_playing_artist',
        'now_playing_album_art_url',
        'now_playing_duration_ms',
        'now_playing_started_at',
        'now_playing_position_ms',
        'last_local_command_at',
        'is_playing',
        'shuffle_enabled',
        'repeat_mode',
        'volume_percent',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'guests_can_add_to_queue' => 'boolean',
            'is_playing_fallback' => 'boolean',
            'shuffle_enabled' => 'boolean',
            'location_enforced' => 'boolean',
            'location_lat' => 'float',
            'location_lng' => 'float',
            'closed_at' => 'datetime',
            'now_playing_started_at' => 'datetime',
            'last_local_command_at' => 'datetime',
            'is_playing' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'invite_code';
    }

    public static function generateInviteCode(): string
    {
        $segment = fn () => strtoupper(Str::random(6));

        return sprintf('%s-%s-%s', $segment(), $segment(), $segment());
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function playbackProvider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'playback_provider_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(RoomMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->whereNull('left_at');
    }

    /** Guests who've joined but are waiting for the host to let them in. */
    public function pendingMembers(): HasMany
    {
        return $this->activeMembers()->where('role', 'guest')->whereNull('approved_at');
    }

    public function queueItems(): HasMany
    {
        return $this->hasMany(QueueItem::class);
    }

    public function pendingQueueItems(): HasMany
    {
        return $this->queueItems()->whereNull('played_at')->orderBy('position');
    }

    public function activityEvents(): HasMany
    {
        return $this->hasMany(ActivityEvent::class);
    }

    public function nowPlaying(): BelongsTo
    {
        return $this->belongsTo(QueueItem::class, 'now_playing_queue_item_id');
    }

    /**
     * The currently-playing track's details, straight from Spotify's live
     * state (see ShowRoom::syncWithSpotify()) — not backed by a QueueItem,
     * since what's playing isn't always something that was queued (a
     * shuffled playlist track, for instance).
     */
    public function nowPlayingDetails(): ?object
    {
        if (! $this->now_playing_track_id) {
            return null;
        }

        return (object) [
            'name' => $this->now_playing_name,
            'artist' => $this->now_playing_artist,
            'album_art_url' => $this->now_playing_album_art_url,
            'duration_ms' => $this->now_playing_duration_ms,
        ];
    }

    /**
     * Current playback position in ms. While playing, `now_playing_started_at` is
     * backdated by the position at play/resume/seek time, so elapsed wall-clock
     * time since then equals the true position — no periodic writes needed.
     */
    public function currentPositionMs(): int
    {
        if (! $this->is_playing || ! $this->now_playing_started_at) {
            return $this->now_playing_position_ms;
        }

        return (int) $this->now_playing_started_at->diffInMilliseconds(Carbon::now(), true);
    }

    /**
     * Spotify's own API has a well-known read-after-write lag — a GET right
     * after a play/pause/skip command can still report the old state for a
     * second or two. Polling that lags into a just-issued command looks
     * indistinguishable from a genuine external change, so background sync
     * gives every local command a short grace window before trusting a
     * fresh read over it again.
     */
    public function commandedRecently(): bool
    {
        return $this->last_local_command_at
            && $this->last_local_command_at->diffInSeconds(Carbon::now()) < 4;
    }

    public function hasLocationBoundary(): bool
    {
        return $this->location_lat !== null && $this->location_lng !== null && $this->location_radius_m !== null;
    }

    /**
     * Great-circle distance between the room's registered location and a point, in meters.
     */
    public function distanceToMeters(float $lat, float $lng): ?float
    {
        if (! $this->hasLocationBoundary()) {
            return null;
        }

        $earthRadius = 6371000;

        $latFrom = deg2rad($this->location_lat);
        $lngFrom = deg2rad($this->location_lng);
        $latTo = deg2rad($lat);
        $lngTo = deg2rad($lng);

        $latDelta = $latTo - $latFrom;
        $lngDelta = $lngTo - $lngFrom;

        $a = sin($latDelta / 2) ** 2 + cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2;
        $c = 2 * asin(min(1, sqrt($a)));

        return $earthRadius * $c;
    }

    /**
     * $bufferMeters adds slack to the radius for periodic re-checks, so ordinary
     * GPS drift near the edge doesn't flap a guest in and out of range.
     */
    public function isWithinBoundary(float $lat, float $lng, int $bufferMeters = 0): bool
    {
        $distance = $this->distanceToMeters($lat, $lng);

        return $distance === null || $distance <= $this->location_radius_m + $bufferMeters;
    }
}
