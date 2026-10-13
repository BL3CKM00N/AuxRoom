<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\PartyScreen;
use App\Livewire\Dashboard\ShowRoom;
use App\Models\Room;
use App\Models\User;
use App\Services\RoomMembership;
use App\Services\Spotify\WatchState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class HealthAndLiveStatusTest extends TestCase
{
    use RefreshDatabase;

    private function room(): array
    {
        $host = User::factory()->create();
        $room = Room::create(['invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $host->id, 'playback_provider_id' => $host->id])->refresh();
        app(RoomMembership::class)->joinAsHost($room);

        return [$host, $room];
    }

    public function test_the_health_route_is_ok_while_the_watcher_reports_in(): void
    {
        config(['broadcasting.default' => 'log']);
        Cache::flush();
        WatchState::beat();

        $this->getJson('/health/watcher')->assertOk()->assertJson(['ok' => true, 'checks' => ['watcher' => ['ok' => true], 'database' => ['ok' => true], 'cache' => ['ok' => true]]])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_health_route_returns_503_when_the_watcher_is_silent(): void
    {
        config(['broadcasting.default' => 'log']);
        Cache::flush();

        $this->getJson('/health/watcher')->assertStatus(503)->assertJson(['ok' => false, 'checks' => ['watcher' => ['ok' => false], 'database' => ['ok' => true]]]);
    }

    public function test_the_watcher_goes_stale_after_the_heartbeat_window(): void
    {
        config(['broadcasting.default' => 'log']);
        Cache::flush();
        WatchState::beat();
        $this->travel(WatchState::HEARTBEAT_TTL + 2)->seconds();

        $this->getJson('/health/watcher')->assertStatus(503);
    }

    public function test_the_health_route_returns_503_when_reverb_is_not_listening(): void
    {
        config(['broadcasting.default' => 'reverb', 'reverb.servers.reverb.port' => 1]);
        Cache::flush();
        WatchState::beat();

        $this->getJson('/health/watcher')->assertStatus(503)->assertJson(['checks' => ['reverb' => ['ok' => false], 'watcher' => ['ok' => true]]]);
    }

    public function test_the_health_route_says_nothing_secret(): void
    {
        config(['broadcasting.default' => 'log']);
        WatchState::beat();

        $body = $this->getJson('/health/watcher')->getContent();

        foreach ([config('app.key'), config('database.connections.sqlite.database'), 'password', 'token'] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $body);
        }
    }

    public function test_the_room_pages_show_the_live_indicator_and_tell_it_about_the_watcher(): void
    {
        [$host, $room] = $this->room();
        Cache::flush();

        $page = $this->actingAs($host)->get(route('rooms.show', $room))->assertOk()->getContent();
        $this->assertStringContainsString('liveStatus(false)', $page, 'starts amber when the watcher is silent');

        WatchState::beat();
        $page = $this->actingAs($host)->get(route('rooms.show', $room))->getContent();
        $this->assertStringContainsString('liveStatus(true)', $page);

        Livewire::actingAs($host)->test(ShowRoom::class, ['room' => $room])->assertDispatched('watcher-status', alive: true);
        Livewire::test(PartyScreen::class, ['room' => $room])->assertDispatched('watcher-status', alive: true);

        $party = $this->get(route('rooms.party', $room))->getContent();
        $this->assertStringContainsString('liveStatus(', $party);
    }

    public function test_the_health_route_counts_watched_rooms_that_are_behind_without_failing(): void
    {
        config(['broadcasting.default' => 'log']);
        Cache::flush();
        WatchState::beat();
        [, $fresh] = $this->room();
        $stale = Room::create(['invite_code' => 'DDDDDD-EEEEEE-FFFFFF', 'host_id' => $fresh->host_id, 'playback_provider_id' => $fresh->host_id]);
        $unwatched = Room::create(['invite_code' => 'GGGGGG-HHHHHH-JJJJJJ', 'host_id' => $fresh->host_id, 'playback_provider_id' => $fresh->host_id]);

        WatchState::touch($fresh);
        WatchState::markVerified($fresh);
        WatchState::touch($stale);   // looked at, but Spotify was never confirmed

        $this->getJson('/health/watcher')->assertOk()->assertJson(['ok' => true, 'checks' => ['rooms' => ['ok' => true, 'watched' => 2, 'behind' => 1]]]);

        $this->travel(11)->seconds();
        WatchState::beat();
        WatchState::touch($fresh);
        WatchState::touch($stale);

        $this->getJson('/health/watcher')->assertJson(['checks' => ['rooms' => ['watched' => 2, 'behind' => 2]]]);
    }

    public function test_the_debug_panel_only_shows_when_asked_for(): void
    {
        [$host, $room] = $this->room();

        $this->actingAs($host)->get(route('rooms.show', $room))->assertDontSee('x-data="debugHud()"', false);
        $this->actingAs($host)->get(route('rooms.show', [$room, 'debug' => 1]))->assertSee('x-data="debugHud()"', false);
        $this->get(route('rooms.party', [$room, 'debug' => 1]))->assertSee('x-data="debugHud()"', false);
    }

    public function test_the_slow_updates_guide_exists(): void
    {
        $this->assertNotNull(\App\Support\Errors\ErrorCatalog::find('NET-SLOW'));
    }
}
