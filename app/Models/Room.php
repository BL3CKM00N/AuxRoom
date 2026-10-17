<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'smart_shuffle_enabled',
        'repeat_mode',
        'volume_percent',
        'volume_supported',
        'queue_limit_per_guest',
        'duplicate_guard_tracks',
        'block_explicit',
        'now_playing_artists',
        'now_playing_explicit',
        'now_playing_tint',
        'waiting_list_enabled',
        'rotation_enabled',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'guests_can_add_to_queue' => 'boolean',
            'is_playing_fallback' => 'boolean',
            'shuffle_enabled' => 'boolean',
            'smart_shuffle_enabled' => 'boolean',
            'volume_supported' => 'boolean',
            'block_explicit' => 'boolean',
            'last_activity_at' => 'datetime',
            'waiting_list_enabled' => 'boolean',
            'rotation_enabled' => 'boolean',
            'now_playing_explicit' => 'boolean',
            'now_playing_artists' => 'array',
            'now_playing_tint' => 'array',
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

        // Every local command stamps last_local_command_at. That tells the background watcher to
        // poll fast for a moment and not to trust a read that still shows the state from before
        // the command (the specific commands narrow this down, see HandlesPlayback).
        static::saved(function (Room $room) {
            if ($room->wasChanged('last_local_command_at') && $room->last_local_command_at) {
                \App\Services\Spotify\WatchState::expect($room, 'other', $room->now_playing_track_id);
            }
        });
    }

    /**
     * Setting the start time also keeps it to the millisecond (now_playing_started_ms): the datetime column
     * only holds whole seconds, which made every position up to a second wrong.
     */
    protected function nowPlayingStartedAt(): Attribute
    {
        return Attribute::make(set: function ($value) {
            $time = $value === null ? null : Carbon::parse($value);

            return [
                'now_playing_started_at' => $time?->format('Y-m-d H:i:s'),
                'now_playing_started_ms' => $time ? $time->getTimestamp() * 1000 + intdiv($time->micro, 1000) : null,
            ];
        });
    }

    /** A person did something here recently: the room is read from Spotify as often as accuracy needs. */
    public const ACTIVE_FOR_SECONDS = 900;

    /** After this long without anyone doing anything, the room is read only now and then. */
    public const QUIET_FOR_SECONDS = 3600;

    /** @return 'active'|'quiet'|'idle' */
    public function activityTier(): string
    {
        $since = ($this->last_activity_at ?? $this->created_at)?->diffInSeconds(now(), true) ?? 0;

        return match (true) {
            $since < self::ACTIVE_FOR_SECONDS => 'active',
            $since < self::QUIET_FOR_SECONDS => 'quiet',
            default => 'idle',
        };
    }

    /**
     * Something a person did (pressed a control, added a song, joined, opened the room), as opposed to a screen
     * refreshing itself. It decides how often Spotify is asked about the room, and ends a quiet spell at once.
     */
    public function markActive(): void
    {
        $was = $this->activityTier();

        // Pressing play and then skip is two actions, not two writes.
        if ($was === 'active' && $this->last_activity_at && $this->last_activity_at->diffInSeconds(now(), true) < 30) {
            return;
        }

        $this->forceFill(['last_activity_at' => now()])->saveQuietly();

        if ($was !== 'active') {
            \App\Services\Spotify\WatchState::wake($this);
        }
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
        return $this->queueItems()->whereNull('played_at')->whereNull('removed_at')->orderBy('position');
    }

    public function blockedItems(): HasMany
    {
        return $this->hasMany(BlockedItem::class);
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

    /** Spotify's Smart Shuffle is on: shuffled, with recommended songs mixed in. Only meaningful while shuffle is. */
    public function smartShuffleOn(): bool
    {
        return $this->shuffle_enabled && $this->smart_shuffle_enabled;
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
        return $this->positionAtMs(Carbon::now()->getPreciseTimestamp(3));
    }

    /** The position at a given moment (epoch milliseconds), by the stored start. */
    public function positionAtMs(float $epochMs): int
    {
        if (! $this->is_playing) {
            return $this->now_playing_position_ms;
        }

        if ($this->now_playing_started_ms !== null) {
            return max(0, (int) round($epochMs - $this->now_playing_started_ms));
        }

        if (! $this->now_playing_started_at) {
            return $this->now_playing_position_ms;
        }

        return (int) max(0, $epochMs - $this->now_playing_started_at->getTimestamp() * 1000);
    }

    /** When the current song started (or would have, had it played from the beginning), in server epoch milliseconds; null while not playing. */
    public function startedAtMs(): ?int
    {
        if (! $this->is_playing) {
            return null;
        }

        return $this->now_playing_started_ms ?? ($this->now_playing_started_at ? $this->now_playing_started_at->getTimestamp() * 1000 : null);
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

    /**
     * The queue rules that are on, as short phrases for guests to read ("Up to 3 songs waiting").
     * The block list is not spelled out; it only says that one exists.
     *
     * @return array<int, string>
     */
    public function queueRulesSummary(): array
    {
        $rules = [];

        if ($this->queue_limit_per_guest) {
            $rules[] = 'Up to '.$this->queue_limit_per_guest.($this->queue_limit_per_guest === 1 ? ' song' : ' songs').' waiting per person';
        }

        if ($this->duplicate_guard_tracks) {
            $rules[] = 'No repeats within '.$this->duplicate_guard_tracks.' tracks';
        }

        if ($this->block_explicit) {
            $rules[] = 'No explicit songs';
        }

        if ($this->blockedItems()->exists()) {
            $rules[] = 'Some songs and artists are blocked';
        }

        return $rules;
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
