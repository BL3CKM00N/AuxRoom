<?php

namespace App\Services\Spotify;

use RuntimeException;

/**
 * A read that needs to tell "Spotify said there's nothing there" apart from
 * "the request itself failed" (rate limit, 5xx, timeout, bad token) throws
 * this for the second case, so callers can leave known state alone instead
 * of treating an outage like an empty answer.
 *
 * $status is the HTTP status when there was one, and $retryAfter the seconds
 * Spotify asked us to wait (rate limits), so the background watcher backs off
 * for as long as it was told.
 */
class SpotifyRequestFailed extends RuntimeException
{
    public ?int $retryAfter = null;

    public static function withStatus(string $message, ?int $status, ?int $retryAfter = null): self
    {
        $e = new self($message, (int) $status);
        $e->retryAfter = $retryAfter;

        return $e;
    }

    public function status(): ?int
    {
        return $this->code ?: null;
    }
}
