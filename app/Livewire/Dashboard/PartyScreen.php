<?php

namespace App\Livewire\Dashboard;

use App\Models\Room;
use App\Services\Spotify\SpotifyClientFactory;
use Livewire\Attributes\On;
use Livewire\Component;

class PartyScreen extends Component
{
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

    /** Spotify's own live queue — see ShowRoom::getQueueProperty() for why. */
    public function getUpNextProperty()
    {
        if (app(SpotifyClientFactory::class)->isMock($this->room) || ! $this->room->playbackProvider?->hasSpotifyConnected()) {
            return collect();
        }

        // Collapse to unique tracks — with repeat-track on, Spotify's raw
        // queue is just the currently playing track listed many times over.
        return collect(app(SpotifyClientFactory::class)->forRoom($this->room)->getQueue())
            ->unique('id')
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
            ->layout('layouts.room', ['title' => 'Party screen']);
    }
}
