<?php

namespace App\Services\Spotify;

/**
 * Why the last playback command Spotify was sent failed, in words a host can
 * act on. The client methods only return true or false, so the web client
 * records the status here (one instance per request) and the components ask
 * for a message when a command comes back false.
 *
 * The case that matters most: a phone that has gone to sleep (iOS suspends a
 * paused Spotify app) drops off Spotify Connect, and Spotify answers commands
 * aimed at it with 502 "Bad gateway" or 404 "no active device". The generic
 * "try reselecting the device" sent people to the wrong place.
 */
class CommandFailure
{
    private ?int $status = null;

    private ?string $reason = null;

    private ?string $message = null;

    public function clear(): void
    {
        $this->status = null;
        $this->reason = null;
        $this->message = null;
    }

    public function record(?int $status, ?string $body): void
    {
        $this->status = $status;
        $error = $body ? (json_decode($body, true)['error'] ?? null) : null;
        $this->reason = is_array($error) ? ($error['reason'] ?? null) : null;
        $this->message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : null;
    }

    /**
     * What Spotify answered, for the "copy details" block. Only the status and
     * Spotify's own reason and message: never the request, URL or any token.
     */
    public function summary(): ?string
    {
        if ($this->status === null && $this->reason === null) {
            return 'No answer from Spotify (timeout or connection problem).';
        }

        return trim("Spotify answered {$this->status}".($this->reason ? " ({$this->reason})" : '').($this->message ? ": {$this->message}" : ''));
    }

    /** True when the device the command was aimed at can't be reached. */
    public function deviceAsleep(): bool
    {
        return in_array($this->status, [502, 503, 504], true)
            || $this->reason === 'NO_ACTIVE_DEVICE'
            || $this->status === 404;
    }

    public function premiumRequired(): bool
    {
        return $this->reason === 'PREMIUM_REQUIRED';
    }

    /** The specific message for what went wrong, or $generic when there's nothing more to say. */
    public function message(string $generic): string
    {
        if ($this->deviceAsleep()) {
            return 'Spotify on your playback device is asleep or closed. Open the Spotify app on it, then try again.';
        }

        if ($this->premiumRequired()) {
            return 'Spotify only lets Premium accounts control playback.';
        }

        return $generic;
    }
}
