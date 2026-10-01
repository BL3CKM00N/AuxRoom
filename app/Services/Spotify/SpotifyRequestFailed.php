<?php

namespace App\Services\Spotify;

use RuntimeException;

/**
 * A read that needs to tell "Spotify said there's nothing there" apart from
 * "the request itself failed" (rate limit, 5xx, timeout, bad token) throws
 * this for the second case, so callers can leave known state alone instead
 * of treating an outage like an empty answer.
 */
class SpotifyRequestFailed extends RuntimeException {}
