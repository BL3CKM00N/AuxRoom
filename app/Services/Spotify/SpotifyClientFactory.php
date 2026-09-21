<?php

namespace App\Services\Spotify;

use App\Models\Room;

class SpotifyClientFactory
{
    public function forRoom(Room $room): SpotifyClientContract
    {
        $account = $room->playbackProvider?->spotifyAccount;

        if ($account && $account->access_token) {
            return new SpotifyWebApiClient($account);
        }

        return new MockSpotifyClient;
    }

    public function isMock(Room $room): bool
    {
        return $this->forRoom($room) instanceof MockSpotifyClient;
    }
}
