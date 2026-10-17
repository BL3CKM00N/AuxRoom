<?php

namespace App\Livewire\Dashboard\Concerns;

use App\Services\Spotify\SpotifyBudget;
use App\Services\Spotify\SpotifyClientFactory;
use App\Services\Spotify\WatchState;

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

    /**
     * Seconds Spotify has asked this room's Spotify app to stay away from playback for (its quota ran out), or
     * null. While it lasts nothing can be read, so "no device" would be a guess: the page says what is going on.
     */
    public function getSpotifyLimitedProperty(): ?int
    {
        return $this->isMock ? null : SpotifyBudget::playerRemainingForRoom($this->room);
    }

    public function getDevicesProperty(): array
    {
        if ($this->isMock) {
            return app(SpotifyClientFactory::class)->forRoom($this->room)->getDevices(allowCached: true);
        }

        if (! $this->room->playbackProvider?->hasSpotifyConnected()) {
            return [];
        }

        // The watcher keeps the device list; a render does not ask Spotify when it can use that.
        if (WatchState::watcherAlive()) {
            return WatchState::devices($this->room)
                ?? tap(app(SpotifyClientFactory::class)->forRoom($this->room)->getDevices(allowCached: true), fn ($d) => WatchState::putDevices($this->room, $d));
        }

        return app(SpotifyClientFactory::class)->forRoom($this->room)->getDevices(allowCached: true);
    }
}
