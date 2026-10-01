<?php

namespace App\Livewire\Dashboard\Concerns;

use App\Services\Spotify\SpotifyClientFactory;

/**
 * "Is this room on the mock client, and what Spotify Connect devices are
 * currently visible to it?" Shared by the host/guest dashboard and the Party
 * Screen so both can tell "no Spotify client open anywhere" apart from an
 * ordinary empty queue. Expects the composing component to have a `$room`.
 */
trait ResolvesSpotifyDevices
{
    public function getIsMockProperty(): bool
    {
        return app(SpotifyClientFactory::class)->isMock($this->room);
    }

    /**
     * Spotify permanently rejected the provider's credentials (revoked access,
     * rotated client secret). Playback keeps going on the device, but AuxRoom
     * can neither see nor control it until the provider reconnects.
     */
    public function getSpotifyNeedsReconnectProperty(): bool
    {
        return (bool) $this->room->playbackProvider?->spotifyAccount?->needsReconnect();
    }

    public function getDevicesProperty(): array
    {
        if ($this->isMock) {
            return app(SpotifyClientFactory::class)->forRoom($this->room)->getDevices(allowCached: true);
        }

        if (! $this->room->playbackProvider?->hasSpotifyConnected()) {
            return [];
        }

        return app(SpotifyClientFactory::class)->forRoom($this->room)->getDevices(allowCached: true);
    }
}
