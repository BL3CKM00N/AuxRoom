<?php

namespace App\Services\Spotify;

use App\Models\Room;
use App\Models\SpotifyAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * What Spotify has told us about how much it will let this app ask, and how much we ask.
 *
 * Spotify limits each Spotify app (its client id), not each listener, and its player endpoints
 * (/me/player, its queue, devices and every command) have their own, much stricter, quota. When that is
 * used up it answers 429 "QUOTA_EXCEEDED" with a Retry-After that can be hours, while the rest of the API
 * (profile, search) keeps working. Asking again before then does nothing but risk a longer wait, so the first
 * 429 is remembered here and every caller, the watcher and the pages alike, stays quiet until it is over.
 */
final class SpotifyBudget
{
    /** Nobody should be told to wait longer than this, whatever Spotify says. */
    private const MAX_BLOCK_SECONDS = 86400;

    /**
     * Reads (what is playing, the queue, devices) one Spotify app may send a minute, however many rooms and screens
     * there are. Commands are never held back. Spotify's own quota is not published and runs out for hours, so this
     * is a ceiling well under what it seems to allow: a busy minute slows the reads down, it does not lock anyone out.
     */
    public const READS_PER_MINUTE = 120;

    /** A 429 without a Retry-After. */
    private const DEFAULT_BLOCK_SECONDS = 30;

    /** Player endpoints: the playback state, queue, devices and every command. */
    public static function isPlayerUrl(string $url): bool
    {
        return str_contains((string) parse_url($url, PHP_URL_PATH), '/v1/me/player');
    }

    private static function key(SpotifyAccount $account, string $group): string
    {
        return 'spotify:limited:'.sha1((string) $account->client_id).':'.$group;
    }

    private static function group(string $url): string
    {
        return self::isPlayerUrl($url) ? 'player' : 'other';
    }

    /** Seconds Spotify has asked this app to wait before using $url again, or null. */
    public static function remaining(SpotifyAccount $account, string $url): ?int
    {
        $until = Cache::get(self::key($account, self::group($url)));

        return $until !== null && $until > now()->getTimestamp() ? (int) $until - now()->getTimestamp() : null;
    }

    /** Seconds until the player endpoints work again for this account's app, or null. */
    public static function playerRemaining(SpotifyAccount $account): ?int
    {
        return self::remaining($account, 'https://api.spotify.com/v1/me/player');
    }

    public static function playerRemainingForRoom(Room $room): ?int
    {
        $account = $room->playbackProvider?->spotifyAccount;

        return $account ? self::playerRemaining($account) : null;
    }

    /** Remembers a 429 from Spotify, for as long as it asked. */
    public static function noteResponse(SpotifyAccount $account, string $url, ?Response $response): void
    {
        if ($response?->status() !== 429) {
            return;
        }

        $seconds = (int) $response->header('Retry-After') ?: self::DEFAULT_BLOCK_SECONDS;
        $seconds = min(max($seconds, 1), self::MAX_BLOCK_SECONDS);

        Cache::put(self::key($account, self::group($url)), now()->getTimestamp() + $seconds, $seconds);
    }

    /**
     * Takes one read from this minute's allowance. Null: go ahead. A number: the allowance is gone, and this is how
     * many seconds until the next minute begins.
     */
    public static function takeRead(SpotifyAccount $account): ?int
    {
        $key = 'spotify:reads:'.sha1((string) $account->client_id).':'.now()->format('YmdHi');

        Cache::add($key, 0, 90);
        $used = Cache::increment($key);

        if (! is_int($used) || $used <= self::READS_PER_MINUTE) {
            return null;
        }

        if (Cache::add($key.':warned', 1, 90)) {
            \Illuminate\Support\Facades\Log::warning('Spotify read allowance for this minute is used up', ['reads_allowed' => self::READS_PER_MINUTE]);
        }

        return max(1, 60 - now()->second);
    }

    /** Counts a request that was really sent, for the hourly total in /health/watcher. */
    public static function countCall(SpotifyAccount $account): void
    {
        $key = 'spotify:calls:'.sha1((string) $account->client_id).':'.now()->format('YmdH');

        Cache::add($key, 0, 7200);
        Cache::increment($key);
    }

    public static function callsThisHour(SpotifyAccount $account): int
    {
        return (int) Cache::get('spotify:calls:'.sha1((string) $account->client_id).':'.now()->format('YmdH'), 0);
    }

    /** "11 h 40 min", "12 min", "a few seconds". */
    public static function humanize(int $seconds): string
    {
        if ($seconds < 60) {
            return 'a few seconds';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = (int) ceil(($seconds % 3600) / 60);

        if ($minutes === 60) {
            $hours++;
            $minutes = 0;
        }

        return $hours > 0 ? "{$hours} h".($minutes ? " {$minutes} min" : '') : "{$minutes} min";
    }
}
