<?php

namespace Tests\Feature;

use App\Events\RoomUpdated;
use App\Livewire\Dashboard\PartyScreen;
use App\Livewire\Dashboard\ShowRoom;
use App\Models\Room;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\RoomMembership;
use App\Services\Spotify\RoomWatcher;
use App\Services\Spotify\WatchState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Invented tracks only (this repo is public); real response shapes. */
class RoomWatcherTest extends TestCase
{
    use RefreshDatabase;

    private const TRACKS = ['t1' => 'Alpha Anthem', 't2' => 'Bravo Ballad', 't3' => 'Charlie Chorus'];

    private function room(array $attributes = []): Room
    {
        $host = User::factory()->create();
        SpotifyAccount::create([
            'user_id' => $host->id, 'client_id' => 'id', 'client_secret' => 'secret',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
        ]);

        $room = Room::create($attributes + [
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $host->id, 'playback_provider_id' => $host->id,
            'is_playing' => true, 'repeat_mode' => 'off', 'now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha Anthem',
            'now_playing_artist' => 'Nobody', 'now_playing_duration_ms' => 180000, 'now_playing_position_ms' => 1000,
            'now_playing_started_at' => now()->subSecond(), 'now_playing_context_uri' => 'spotify:playlist:testplaylist01',
        ])->refresh();
        app(RoomMembership::class)->joinAsHost($room);

        Cache::flush();

        // A room that has been running: the watcher confirmed it a moment ago, so opening it asks Spotify nothing.
        \App\Services\Spotify\WatchState::markVerified($room);

        return $room;
    }

    private function track(string $id): array
    {
        return ['id' => $id, 'uri' => "spotify:track:{$id}", 'name' => self::TRACKS[$id], 'type' => 'track', 'duration_ms' => 180000, 'artists' => [['name' => 'Nobody']], 'album' => ['images' => []]];
    }

    private function playerState(string $track, bool $playing = true, int $progress = 1000): array
    {
        return [
            'is_playing' => $playing, 'progress_ms' => $progress, 'shuffle_state' => false, 'repeat_state' => 'off',
            'context' => ['uri' => 'spotify:playlist:testplaylist01', 'type' => 'playlist'],
            'device' => ['id' => 'd1', 'supports_volume' => true, 'volume_percent' => 50], 'item' => $this->track($track),
        ];
    }

    /** @param array<int, array|null> $states what /me/player answers on successive calls (null: 204) */
    private function spotify(array $states, array $queue = ['t2', 't3']): void
    {
        $sequence = Http::sequence();
        foreach ($states as $state) {
            $state === null ? $sequence->push('', 204) : $sequence->push($state);
        }

        Http::fake([
            'api.spotify.com/v1/me/player/queue*' => Http::response(['queue' => array_map(fn ($id) => $this->track($id), $queue)]),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => [['id' => 'd1', 'name' => 'Laptop', 'type' => 'Computer', 'is_active' => true, 'supports_volume' => true]]]),
            'api.spotify.com/v1/playlists/*' => Http::response(['items' => array_map(fn ($id) => ['item' => $this->track($id)], array_keys(self::TRACKS)), 'next' => null]),
            'api.spotify.com/v1/me/player/*' => Http::response('', 204),
            'api.spotify.com/v1/me/player' => $sequence,
        ]);
    }

    private function playerReads(): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'GET' && parse_url($r->url(), PHP_URL_PATH) === '/v1/me/player')->count();
    }

    // ---- who gets watched, and how often

    public function test_the_cadence_follows_who_is_looking_and_whether_a_command_was_just_sent(): void
    {
        $room = $this->room();
        $watcher = app(RoomWatcher::class);

        $this->assertNull($watcher->cadence($room), 'nobody has looked: leave it alone');

        WatchState::touch($room);
        $this->assertSame(1.0, $watcher->cadence($room), 'someone is looking: every second');

        $this->travel(WatchState::WATCHING_SECONDS + 5)->seconds();
        $this->assertSame(5.0, $watcher->cadence($room), 'looked at a little while ago: every five seconds');

        $this->travel(WatchState::RECENT_SECONDS)->seconds();
        $this->assertNull($watcher->cadence($room), 'forgotten again');

        WatchState::expect($room, 'skip', 't1');
        $this->assertSame(0.3, $watcher->cadence($room), 'right after a command: fast');
    }

    public function test_only_open_connected_real_rooms_are_watchable(): void
    {
        $room = $this->room();
        $watcher = app(RoomWatcher::class);
        $this->assertTrue($watcher->watchable($room));

        $room->update(['closed_at' => now()]);
        $this->assertFalse($watcher->watchable($room->refresh()), 'closed');

        $room->update(['closed_at' => null]);
        $room->playbackProvider->spotifyAccount->update(['needs_reconnect_at' => now()]);
        $this->assertFalse($watcher->watchable($room->refresh()), 'Spotify turned the login down');

        $demo = Room::create(['invite_code' => 'DDDDDD-EEEEEE-FFFFFF', 'host_id' => $room->host_id, 'playback_provider_id' => User::factory()->create()->id]);
        $this->assertFalse($watcher->watchable($demo->refresh()), 'demo mode has no Spotify');
    }

    // ---- no more four-second blind spot

    public function test_a_skip_is_confirmed_as_soon_as_spotify_shows_the_new_track_not_after_four_seconds(): void
    {
        Event::fake([RoomUpdated::class]);
        $room = $this->room();
        // Spotify still reports the old track on the first read (its lag), the new one on the second.
        $this->spotify([$this->playerState('t1'), $this->playerState('t2', progress: 200)]);
        WatchState::touch($room);
        WatchState::expect($room, 'skip', 't1');
        $watcher = app(RoomWatcher::class);

        $first = $watcher->tick($room);
        $this->assertSame(0.3, $first, 'still the old track: look again in 0.3 s');
        $this->assertSame('t1', $room->refresh()->now_playing_track_id, 'the stale read was not applied');
        $this->assertNotNull(WatchState::burst($room));

        $second = $watcher->tick($room);
        $room->refresh();

        $this->assertSame('t2', $room->now_playing_track_id, 'applied on the very next look');
        $this->assertSame('Bravo Ballad', $room->now_playing_name);
        $this->assertNull(WatchState::burst($room), 'confirmed, so the burst is over');
        $this->assertSame(1.0, $second, 'and polling settles back to once a second');
        Event::assertDispatched(RoomUpdated::class);
    }

    public function test_if_spotify_never_shows_the_effect_the_burst_ends_and_its_answer_is_taken(): void
    {
        $room = $this->room();
        $this->spotify([$this->playerState('t1'), $this->playerState('t1'), $this->playerState('t1')]);
        WatchState::touch($room);
        WatchState::expect($room, 'skip', 't1');
        $watcher = app(RoomWatcher::class);

        $this->assertSame(0.3, $watcher->tick($room));
        $this->travel(WatchState::BURST_SECONDS + 1)->seconds();
        $watcher->tick($room);

        $this->assertNull(WatchState::burst($room), 'the window ran out: whatever Spotify says is the truth');
    }

    public function test_a_pause_is_confirmed_when_spotify_stops_not_before(): void
    {
        $room = $this->room();
        $this->spotify([$this->playerState('t1', playing: true), $this->playerState('t1', playing: false, progress: 4000)]);
        WatchState::touch($room);
        WatchState::expect($room, 'pause', 't1');
        $watcher = app(RoomWatcher::class);

        $watcher->tick($room);
        $this->assertTrue($room->refresh()->is_playing, 'a read that still says playing is the lag');

        $watcher->tick($room);
        $this->assertFalse($room->refresh()->is_playing);
    }

    public function test_without_a_command_in_flight_a_change_made_in_the_spotify_app_is_applied_at_once(): void
    {
        $room = $this->room();
        $this->spotify([$this->playerState('t3', progress: 500)]);
        WatchState::touch($room);

        app(RoomWatcher::class)->tick($room);

        $this->assertSame('t3', $room->refresh()->now_playing_track_id);
    }

    public function test_every_local_command_makes_the_watcher_poll_fast_for_a_moment(): void
    {
        $room = $this->room();

        $room->update(['last_local_command_at' => now()]);

        $this->assertSame('other', WatchState::burst($room)['kind']);
    }

    // ---- failures

    public function test_a_rate_limit_backs_off_for_as_long_as_spotify_asked_and_changes_nothing(): void
    {
        $room = $this->room();
        Http::fake(['api.spotify.com/v1/me/player' => Http::response('', 429, ['Retry-After' => '7'])]);
        WatchState::touch($room);

        $wait = app(RoomWatcher::class)->tick($room);

        $this->assertGreaterThanOrEqual(7.0, $wait);
        $this->assertSame('t1', $room->refresh()->now_playing_track_id);
        $this->assertTrue($room->is_playing);
    }

    public function test_repeated_failures_back_off_further_up_to_a_limit(): void
    {
        $room = $this->room();
        Http::fake(['api.spotify.com/v1/me/player' => Http::response('', 503)]);
        $watcher = app(RoomWatcher::class);

        $waits = array_map(fn () => $watcher->tick($room), range(1, 8));

        $this->assertSame($waits, collect($waits)->sort()->values()->all(), 'never speeds up while it keeps failing');
        $this->assertLessThanOrEqual(30.0, max($waits));
        $this->assertGreaterThan($waits[0], end($waits));
    }

    // ---- pages never wait on Spotify

    public function test_the_watcher_stores_the_queue_and_devices_so_pages_can_read_them(): void
    {
        $room = $this->room();
        $this->spotify([$this->playerState('t1')]);
        WatchState::touch($room);

        app(RoomWatcher::class)->tick($room);

        $this->assertCount(2, WatchState::queue($room));
        $this->assertSame('Laptop', WatchState::devices($room)[0]['name']);
    }

    public function test_with_the_watcher_running_a_page_render_makes_no_spotify_request(): void
    {
        $room = $this->room();
        $this->spotify([$this->playerState('t1')]);
        WatchState::touch($room);
        app(RoomWatcher::class)->tick($room);

        // Past the client's own 3-second cache, so only the watcher's stored lists can answer the page.
        $this->travel(10)->seconds();
        WatchState::beat();
        WatchState::markVerified($room);   // the watcher keeps a watched room confirmed every second or so
        Http::fake();   // from here on, any request would be a render waiting on Spotify

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])
            ->assertSee('Bravo Ballad')->assertSee('Charlie Chorus');

        Http::assertNothingSent();
    }

    // ---- screens leave the asking to the watcher, unless it is not running

    public function test_the_host_dashboard_polls_spotify_only_when_the_watcher_is_not_running(): void
    {
        $room = $this->room();
        $this->spotify([$this->playerState('t1'), $this->playerState('t1')]);

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->call('heartbeat');
        $withoutWatcher = $this->playerReads();

        WatchState::beat();
        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->call('heartbeat');

        $this->assertGreaterThanOrEqual(1, $withoutWatcher, 'the fallback still asks Spotify');
        $this->assertSame($withoutWatcher, $this->playerReads(), 'with the watcher alive the heartbeat adds no Spotify read');
    }

    public function test_the_party_screen_does_the_same(): void
    {
        $room = $this->room();
        $this->spotify([$this->playerState('t1'), $this->playerState('t1')]);

        Livewire::test(PartyScreen::class, ['room' => $room])->call('poll');
        $withoutWatcher = $this->playerReads();

        WatchState::beat();
        Livewire::test(PartyScreen::class, ['room' => $room])->call('poll');

        $this->assertGreaterThanOrEqual(1, $withoutWatcher);
        $this->assertSame($withoutWatcher, $this->playerReads());
    }

    public function test_screens_tell_the_watcher_someone_is_looking(): void
    {
        // Whole seconds are compared, so a second boundary crossed mid-test would read 1, not 0.
        $this->freezeTime();
        $room = $this->room();
        $this->spotify([$this->playerState('t1')]);
        $this->assertNull(WatchState::secondsSinceTouched($room));

        Livewire::test(PartyScreen::class, ['room' => $room]);
        $this->assertSame(0, WatchState::secondsSinceTouched($room), 'opening the party screen counts');

        Cache::forget("watching:{$room->id}");
        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->call('heartbeat');
        $this->assertNotNull(WatchState::secondsSinceTouched($room), 'so does a dashboard heartbeat');
    }

    public function test_the_polling_intervals_are_the_slow_fallbacks_now_that_pushes_carry_the_news(): void
    {
        $this->assertStringContainsString('wire:poll.10s="heartbeat"', file_get_contents(resource_path('views/livewire/dashboard/show.blade.php')));
        $this->assertStringContainsString('wire:poll.15s="poll"', file_get_contents(resource_path('views/livewire/dashboard/party.blade.php')));
    }

    // ---- the command

    public function test_the_watch_command_beats_and_polls_a_room_someone_is_looking_at(): void
    {
        $room = $this->room();
        $this->spotify([$this->playerState('t3', progress: 100)]);
        WatchState::touch($room);

        $this->artisan('spotify:watch', ['--once' => true])->assertSuccessful();

        $this->assertTrue(WatchState::watcherAlive());
        $this->assertSame('t3', $room->refresh()->now_playing_track_id);
    }

    public function test_the_start_command_runs_the_watcher_under_a_restart_loop(): void
    {
        $this->assertStringContainsString('while true; do php artisan spotify:watch', file_get_contents(base_path('nixpacks.toml')));
    }
}
