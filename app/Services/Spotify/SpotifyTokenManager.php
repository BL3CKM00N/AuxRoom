<?php

namespace App\Services\Spotify;

use App\Models\SpotifyAccount;
use Illuminate\Support\Facades\Http;

class SpotifyTokenManager
{
    public function ensureFreshToken(SpotifyAccount $account): void
    {
        if (! $account->isTokenExpired()) {
            return;
        }

        $this->refresh($account);
    }

    public function refresh(SpotifyAccount $account): void
    {
        $response = Http::asForm()
            ->withBasicAuth($account->client_id, $account->client_secret)
            ->post('https://accounts.spotify.com/api/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
            ]);

        if ($response->failed()) {
            return;
        }

        $data = $response->json();

        $account->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => now()->addSeconds($data['expires_in']),
        ]);
    }
}
