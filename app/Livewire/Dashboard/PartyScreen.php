<?php

namespace App\Livewire\Dashboard;

use App\Models\QueueItem;
use App\Models\Room;
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

    public function getNowPlayingProperty(): ?QueueItem
    {
        return $this->room->nowPlaying;
    }

    public function getCurrentPositionMsProperty(): int
    {
        return $this->room->currentPositionMs();
    }

    public function getMemberCountProperty(): int
    {
        return $this->room->activeMembers()->count();
    }

    public function getUpNextProperty()
    {
        return $this->room->pendingQueueItems()->limit(4)->get();
    }

    public function render()
    {
        return view('livewire.dashboard.party')
            ->layout('layouts.room', ['title' => $this->room->name.' · Party screen']);
    }
}
