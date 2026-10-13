<?php

namespace App\Services\Spotify;

use App\Models\SpotifyAccount;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SpotifyTokenManager
{
    /**
     * How long an account stays untouched after Spotify permanently rejected
     * its credentials, before one cheap retry is allowed. A rejection is
     * almost always real (revoked access, rotated client secret), so retrying
     * every call just burns a round trip each time and hammers the same
     * endpoint the owner's reconnect needs. The occasional retry lets a
     * wrongly flagged account heal itself without anyone doing anything.
     */
    private const RETRY_AFTER_MINUTES = 5;

    /** Token-endpoint errors that mean "these credentials will never work again". */
    private const PERMANENT_ERRORS = ['invalid_grant', 'invalid_client'];

    /** How long to wait for another process that is refreshing the same login. */
    public int $lockWaitSeconds = 5;

    public function ensureFreshToken(SpotifyAccount $account): void
    {
        if (! $this->due($account)) {
            return;
        }

        // The watcher and the web requests all use the same login. Two refreshes at once can each use the same
        // refresh token, and when Spotify has already rotated it the second one is rejected for good, which flags
        // the account as disconnected. So one process refreshes and the others use what it got.
        $lock = $this->acquire($account);

        try {
            // Whoever held the lock may have done it already.
            $account->refresh();

            if (! $this->due($account)) {
                return;
            }

            if ($account->needsReconnect()) {
                // Claim this retry slot first so concurrent requests don't all
                // fire their own; a successful refresh clears the flag again.
                $account->update(['needs_reconnect_at' => now()]);
            }

            $this->refresh($account);
        } finally {
            $lock?->release();
        }
    }

    /** Does this login need a refresh right now? (A login Spotify rejected is only retried now and then.) */
    private function due(SpotifyAccount $account): bool
    {
        if ($account->needsReconnect()) {
            return ! $account->needs_reconnect_at->gt(now()->subMinutes(self::RETRY_AFTER_MINUTES));
        }

        return $account->isTokenExpired();
    }

    /**
     * The lock for this account's refresh, or null when it stayed busy for the whole wait (the caller then
     * looks again and refreshes anyway if it is still due). Falls back to the database when Redis is down:
     * a Redis lock object only fails when it is first used.
     */
    private function acquire(SpotifyAccount $account): ?\Illuminate\Contracts\Cache\Lock
    {
        foreach ([null, 'database'] as $store) {
            try {
                $lock = Cache::store($store)->lock("spotify-refresh:{$account->id}", 15);
                $lock->block($this->lockWaitSeconds);

                return $lock;
            } catch (LockTimeoutException) {
                return null;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return null;
    }

    public function refresh(SpotifyAccount $account): bool
    {
        try {
            $response = Http::asForm()
                ->timeout(5)
                ->withBasicAuth($account->client_id, $account->client_secret)
                ->post('https://accounts.spotify.com/api/token', [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $account->refresh_token,
                ]);
        } catch (ConnectionException) {
            // Transient: say nothing about the credentials, just try again next time.
            return false;
        }

        if ($response->failed()) {
            // Only a definitive rejection flags the account. A 429, a 5xx or
            // anything unrecognised is treated as transient and retried freely.
            if (in_array($response->status(), [400, 401], true)
                && in_array($response->json('error'), self::PERMANENT_ERRORS, true)) {
                $account->update(['needs_reconnect_at' => now()]);
            }

            return false;
        }

        $data = $response->json();

        $account->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => now()->addSeconds($data['expires_in']),
            'needs_reconnect_at' => null,
        ]);

        return true;
    }
}
