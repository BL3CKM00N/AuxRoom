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

class ResumeSyncTest extends TestCase
{
    use RefreshDatabase;

    private function room(): Room
    {
        $host = User::factory()->create();
        $room = Room::create(['invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $host->id, 'playback_provider_id' => $host->id])->refresh();
        app(RoomMembership::class)->joinAsHost($room);

        return $room;
    }

    public function test_the_polled_pages_refresh_the_moment_the_app_comes_back_to_the_foreground(): void
    {
        $room = $this->room();

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])
            ->assertSeeHtml('x-on:app-resumed.window="$wire.heartbeat()"');

        Livewire::test(PartyScreen::class, ['room' => $room])
            ->assertSeeHtml('x-on:app-resumed.window="$wire.poll()"');
    }

    public function test_the_resume_script_is_part_of_the_bundle(): void
    {
        $this->assertStringContainsString("import './resume-sync';", file_get_contents(resource_path('js/app.js')));
        $this->assertStringContainsString("'app-resumed'", file_get_contents(resource_path('js/resume-sync.js')));
    }
}
