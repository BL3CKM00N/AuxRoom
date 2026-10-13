<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\PartyScreen;
use App\Livewire\Dashboard\ShowRoom;
use App\Models\Room;
use App\Models\User;
use App\Services\RoomMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Every screen counts a song's progress from the server's own start time, against the server's own clock. */
class SharedClockTest extends TestCase
{
    use RefreshDatabase;

    private function room(): Room
    {
        $host = User::factory()->create();
        $room = Room::create([
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $host->id, 'playback_provider_id' => $host->id,
            'is_playing' => true, 'now_playing_track_id' => 't1', 'now_playing_name' => 'Song', 'now_playing_artist' => 'Band',
            'now_playing_duration_ms' => 200000, 'now_playing_position_ms' => 0, 'now_playing_started_at' => now()->subMilliseconds(30500),
        ])->refresh();
        app(RoomMembership::class)->joinAsHost($room);
        \App\Services\Spotify\WatchState::markVerified($room);

        return $room;
    }

    public function test_the_time_endpoint_tells_the_servers_clock_in_milliseconds(): void
    {
        $before = Carbon::now()->getPreciseTimestamp(3);
        $response = $this->getJson('/time')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $after = Carbon::now()->getPreciseTimestamp(3);

        $this->assertGreaterThanOrEqual($before - 1, $response->json('now'));
        $this->assertLessThanOrEqual($after + 1, $response->json('now'));
    }

    public function test_the_time_endpoint_needs_no_login_and_leaks_nothing_else(): void
    {
        $this->getJson('/time')->assertOk()->assertExactJsonStructure(['now']);
    }

    public function test_every_page_carries_the_time_it_was_drawn_at_as_a_first_guess(): void
    {
        $html = $this->get('/join')->getContent();

        $this->assertMatchesRegularExpression('/<meta name="server-time" content="\d{13}">/', $html);
    }

    public function test_a_room_sends_the_start_time_not_just_a_position(): void
    {
        $room = $this->room();

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])
            ->assertDispatched('playback-sync', fn ($name, $params) => $params['startedAtMs'] === $room->fresh()->now_playing_started_ms && $params['isPlaying'] === true);

        Livewire::test(PartyScreen::class, ['room' => $room])
            ->assertDispatched('playback-sync', fn ($name, $params) => $params['startedAtMs'] === $room->fresh()->now_playing_started_ms);
    }

    public function test_a_paused_room_has_no_start_time(): void
    {
        $room = $this->room();
        $room->update(['is_playing' => false, 'now_playing_started_at' => null, 'now_playing_position_ms' => 45000]);

        Livewire::test(PartyScreen::class, ['room' => $room])
            ->assertDispatched('playback-sync', fn ($name, $params) => $params['startedAtMs'] === null && $params['positionMs'] === 45000);
    }

    public function test_the_first_paint_already_has_the_start_time(): void
    {
        $room = $this->room();

        $page = $this->actingAs($room->host)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('startedAtMs: '.$room->fresh()->now_playing_started_ms, $page);

        $party = $this->get(route('rooms.party', $room))->getContent();
        $this->assertStringContainsString('startedAtMs: '.$room->fresh()->now_playing_started_ms, $party);
    }

    public function test_the_clock_script_is_part_of_the_app_bundle(): void
    {
        $this->assertStringContainsString("import './server-clock';", file_get_contents(base_path('resources/js/app.js')));
    }
}
