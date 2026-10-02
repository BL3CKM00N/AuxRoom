<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
        'now_playing_context_uri',
        'now_playing_name',
        'now_playing_artist',
        'now_playing_album_art_url',
        'now_playing_duration_ms',
        'now_playing_started_at',
        'now_playing_position_ms',
        'last_local_command_at',
        'is_playing',
        'playback_inactive_at',
        'playlist_finished_at',
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
            'playback_inactive_at' => 'datetime',
            'playlist_finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Anything that starts playback (a local command, or the sync seeing a
        // live state) also ends "inactive", without every writer having to
        // remember to clear it.
        static::saving(function (Room $room) {
            if ($room->is_playing && $room->playlist_finished_at !== null) {
                $room->playlist_finished_at = null;
            }

            if ($room->is_playing && $room->playback_inactive_at !== null) {
                $room->playback_inactive_at = null;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'invite_code';
    }

    /**
     * Codes get read aloud, screenshotted and typed on phones, so the
     * look-alikes (0/O, 1/I/L) are left out. 31 symbols over 18 positions is
     * still ~4e26 combinations. Codes issued before this change may contain
     * those characters and keep working: lookups are exact matches.
     */
    private const INVITE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function generateInviteCode(): string
    {
        $segment = function (): string {
            $segment = '';

            for ($i = 0; $i < 6; $i++) {
                $segment .= self::INVITE_ALPHABET[random_int(0, strlen(self::INVITE_ALPHABET) - 1)];
            }

            return $segment;
        };

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
        // Hidden while playback is inactive, even though the track is still
        // stored for resuming: showing it as "paused" when there's nothing
        // left to play on is what left a dead track on screen.
        if (! $this->now_playing_track_id || $this->playbackInactive() || $this->playlistFinished()) {
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
     * Spotify reported no active device and nothing has played since. The
     * stored track and position are deliberately left in place for resuming.
     */
    public function playbackInactive(): bool
    {
        return $this->playback_inactive_at !== null && ! $this->is_playing;
    }

    /**
     * The playlist ran out with repeat off. Spotify parks on its first track,
     * paused, and the room says "finished" instead of showing that track.
     * The track and context stay stored so Play restarts the playlist.
     */
    public function playlistFinished(): bool
    {
        return $this->playlist_finished_at !== null && ! $this->is_playing;
    }

    /**
     * The playlist or album to resume or jump inside, for the track that is
     * current: Spotify's own live context, else the room's chosen playlist
     * while that is what's playing. Null for a guest-queued track, which has
     * no context to preserve, and for anything that isn't a playlist/album.
     */
    public function playableContextUri(): ?string
    {
        if ($this->now_playing_queue_item_id) {
            return null;
        }

        $uri = $this->now_playing_context_uri
            ?? ($this->is_playing_fallback ? $this->fallback_playlist_uri : null);

        return self::isPlayableContext($uri) ? $uri : null;
    }

    public static function isPlayableContext(?string $uri): bool
    {
        return $uri !== null && preg_match('/^spotify:(playlist|album):[A-Za-z0-9]+$/', $uri) === 1;
    }

    /** The fallback playlist counts as "playing" for display only while playback is live. */
    public function fallbackIsShown(): bool
    {
        return $this->is_playing_fallback && ! $this->playbackInactive() && ! $this->playlistFinished();
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
