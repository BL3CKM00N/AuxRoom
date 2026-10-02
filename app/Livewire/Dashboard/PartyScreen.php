<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Dashboard\Concerns\ResolvesSpotifyDevices;
use App\Models\Room;
use App\Services\Spotify\PlaybackSync;
use App\Services\Spotify\UpcomingQueue;
use App\Support\SharePreview;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class PartyScreen extends Component
{
    use ResolvesSpotifyDevices;

    #[Locked]
    public Room $room;

    public function mount(Room $room): void
    {
        abort_if($room->closed_at, 404);

        $this->room = $room;
    }

    #[On('echo:room.{room.invite_code},RoomUpdated')]
    public function onRoomUpdated(): void
    {
        $this->room->refresh();
    }

    public function poll(): void
    {
        $this->room->refresh();

        // Independent of the host's own dashboard tab (see PlaybackSync) —
        // without this, a Party Screen left open on its own would never
        // notice a track change at all, not just slowly.
        if (! $this->room->commandedRecently()) {
            app(PlaybackSync::class)->sync($this->room);
        }
    }

    public function getNowPlayingProperty(): ?object
    {
        return $this->room->nowPlayingDetails();
    }

    public function getCurrentPositionMsProperty(): int
    {
        return $this->room->currentPositionMs();
    }

    public function getMemberCountProperty(): int
    {
        return $this->room->activeMembers()->count();
    }

    /** The real upcoming tracks, with Spotify's wrap-around padding removed (see UpcomingQueue). */
    public function getUpNextProperty()
    {
        return collect(app(UpcomingQueue::class)->forRoom($this->room))
            ->take(4)
            ->map(fn (array $track) => (object) $track);
    }

    public function render()
    {
        $this->dispatch('playback-sync',
            positionMs: $this->currentPositionMs,
            durationMs: $this->nowPlaying?->duration_ms ?? 0,
            isPlaying: $this->room->is_playing,
        );

        return view('livewire.dashboard.party')
            ->layout('layouts.room', ['title' => 'Party screen', 'preview' => SharePreview::forParty($this->room)]);
    }
}
