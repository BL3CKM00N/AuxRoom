<?php

namespace App\Livewire\Dashboard\Concerns;

/** The host's location-boundary toggle/edit actions, and the guest-side verification check. */
trait HandlesLocationEnforcement
{
    public function toggleLocationEnforced(): void
    {
        if (! $this->isHost) {
            return;
        }

        $enabled = ! $this->room->location_enforced;
        $this->room->update(['location_enforced' => $enabled]);
        $this->logActivity('settings', $enabled
            ? 'Location boundary enforcement turned on.'
            : 'Location boundary enforcement turned off.');
        $this->broadcastUpdate('settings');
    }

    public function setLocationBoundary(float $lat, float $lng, int $radius): void
    {
        if (! $this->isHost) {
            return;
        }

        $this->room->update([
            'location_lat' => $lat,
            'location_lng' => $lng,
            'location_radius_m' => $radius,
        ]);

        $this->logActivity('settings', 'Room location boundary updated.');
        $this->broadcastUpdate('settings');
    }

    /**
     * $isRecheck marks a silent background re-verification (see the ~30s client
     * interval, and the immediate one it triggers on a boundary change, in
     * show.blade.php): it uses a buffered radius to avoid GPS-jitter flapping
     * at the edge, and never surfaces an error on failure — but it does
     * actively revoke the stale verification (rather than just leaving it to
     * decay via passesLocationCheck()'s freshness window), so a host moving
     * the boundary takes effect within one recheck instead of up to ~2
     * minutes. The freshness window still stands as a fallback for when
     * rechecks stop happening at all (backgrounded tab, lost signal).
     */
    public function verifyLocation(float $lat, float $lng, bool $isRecheck = false): void
    {
        if ($this->room->isWithinBoundary($lat, $lng, $isRecheck ? 20 : 0)) {
            $this->member->update(['location_verified_at' => now()]);
            $this->controlError = '';

            return;
        }

        if ($isRecheck) {
            $this->member->update(['location_verified_at' => null]);

            return;
        }

        $this->controlError = 'You need to be closer to the room to control playback.';
    }
}
