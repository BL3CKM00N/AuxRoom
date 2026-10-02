<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\PartyScreen;
use App\Livewire\Dashboard\ShowRoom;
use App\Models\Room;
use App\Models\User;
use App\Services\RoomMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DemoQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_room_without_spotify_lists_the_songs_that_were_added_and_have_not_played(): void
    {
        $host = User::factory()->create();
        $room = Room::create([
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC',
            'host_id' => $host->id,
            'playback_provider_id' => $host->id,
        ])->refresh();
        $member = app(RoomMembership::class)->joinAsHost($room);

        foreach ([['Alpha Demo', 1, null], ['Bravo Demo', 2, null], ['Played Demo', 0, now()]] as [$name, $position, $playedAt]) {
            $room->queueItems()->create([
                'added_by_id' => $member->id, 'spotify_track_id' => 'id'.$position, 'name' => $name, 'artist' => 'Nobody',
                'duration_ms' => 180000, 'position' => $position, 'played_at' => $playedAt,
            ]);
        }

        Livewire::actingAs($host)->test(ShowRoom::class, ['room' => $room])
            ->assertSeeInOrder(['Alpha Demo', 'Bravo Demo'])
            ->assertDontSee('Played Demo');

        Livewire::test(PartyScreen::class, ['room' => $room])
            ->assertSeeInOrder(['Alpha Demo', 'Bravo Demo'])
            ->assertDontSee('Played Demo');
    }
}
