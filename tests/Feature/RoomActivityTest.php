<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\PartyScreen;
use App\Livewire\Dashboard\ShowRoom;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\RoomMembership;
use App\Services\Spotify\RoomWatcher;
use App\Services\Spotify\WatchState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * How often Spotify is asked about a room follows what people are doing in it. A screen refreshing itself is not
 * somebody doing something, and a room where the music plays and nobody touches anything needs far fewer reads.
 */
class RoomActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->host = User::factory()->create();
        SpotifyAccount::create([
            'user_id' => $this->host->id, 'client_id' => 'id', 'client_secret' => 'secret',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
        ]);
    }

    private function room(int $idleSeconds, bool $playing = true, array $attributes = []): Room
    {
        $room = Room::create($attributes + [
            'invite_code' => sprintf('%06d-BBBBBB-CCCCCC', ++$this->made), 'host_id' => $this->host->id, 'playback_provider_id' => $this->host->id,
            'is_playing' => $playing, 'now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha', 'now_playing_artist' => 'Nobody',
            'now_playing_duration_ms' => 200000, 'now_playing_position_ms' => 100000, 'volume_percent' => 50, 'volume_supported' => true,
            'now_playing_started_at' => $playing ? now()->subSeconds(100) : null, 'now_playing_artists' => [],
        ])->refresh();

        // Written straight to the table: setting it through the model would count as somebody being active.
        \DB::table('rooms')->where('id', $room->id)->update(['last_activity_at' => now()->subSeconds($idleSeconds)]);
        app(RoomMembership::class)->joinAsHost($room);
        \DB::table('rooms')->where('id', $room->id)->update(['last_activity_at' => now()->subSeconds($idleSeconds)]);
        Cache::flush();
        WatchState::markVerified($room);

        return $room->fresh();
    }

    // ---- the tiers

    public function test_a_room_is_active_then_quiet_then_idle(): void
    {
        $this->assertSame('active', $this->room(0)->activityTier());
        $this->assertSame('active', $this->room(899)->activityTier());
        $this->assertSame('quiet', $this->room(900)->activityTier());
        $this->assertSame('quiet', $this->room(3599)->activityTier());
        $this->assertSame('idle', $this->room(3600)->activityTier());
        $this->assertSame('idle', $this->room(86400)->activityTier());
    }

    public function test_a_room_nobody_has_done_anything_in_counts_from_when_it_was_made(): void
    {
        $room = $this->room(0);
        \DB::table('rooms')->where('id', $room->id)->update(['last_activity_at' => null, 'created_at' => now()->subHours(3)]);

        $this->assertSame('idle', $room->fresh()->activityTier());
    }

    // ---- how often each is read

    private function pace(Room $room): float
    {
        WatchState::touch($room);

        return app(RoomWatcher::class)->cadence($room->fresh());
    }

    public function test_every_tier_has_its_own_pace_while_somebody_is_looking(): void
    {
        $this->assertSame(RoomWatcher::WATCHED_INTERVAL, $this->pace($this->room(0, playing: true)));
        $this->assertSame(RoomWatcher::IDLE_WATCHED_INTERVAL, $this->pace($this->room(0, playing: false)));
    }

    public function test_a_quiet_room_is_read_slowly(): void
    {
        $this->assertSame(RoomWatcher::QUIET_PLAYING_INTERVAL, $this->pace($this->room(1200, playing: true)));
        $this->assertSame(RoomWatcher::QUIET_PAUSED_INTERVAL, $this->pace($this->room(1200, playing: false)));
    }

    public function test_a_room_where_nothing_has_happened_for_an_hour_is_read_once_in_a_while(): void
    {
        $this->assertSame(RoomWatcher::SLEEPING_PLAYING_INTERVAL, $this->pace($this->room(7200, playing: true)));
        $this->assertSame(RoomWatcher::SLEEPING_PAUSED_INTERVAL, $this->pace($this->room(7200, playing: false)));
    }

    public function test_the_end_of_a_song_is_read_fast_even_in_a_sleeping_room(): void
    {
        $room = $this->room(7200, true, ['now_playing_started_at' => now()->subSeconds(199)]);

        $this->assertSame(RoomWatcher::BURST_INTERVAL, $this->pace($room));
    }

    public function test_a_room_nobody_has_open_is_still_left_alone(): void
    {
        $room = $this->room(7200);

        $this->assertNull(app(RoomWatcher::class)->cadence($room));
    }

    public function test_a_sleeping_room_that_was_only_looked_at_a_little_while_ago_is_not_read_faster_than_asleep(): void
    {
        $room = $this->room(7200, playing: false);
        WatchState::touch($room);
        $this->travel(WatchState::WATCHING_SECONDS + 5)->seconds();
        \DB::table('rooms')->where('id', $room->id)->update(['last_activity_at' => now()->subSeconds(7200)]);

        $this->assertSame(RoomWatcher::SLEEPING_PAUSED_INTERVAL, app(RoomWatcher::class)->cadence($room->fresh()));
    }

    public function test_a_tick_hands_back_the_pace_of_the_room(): void
    {
        Http::fake(['api.spotify.com/v1/me/player/queue*' => Http::response(['queue' => []]), 'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => []]), 'api.spotify.com/v1/me/player' => Http::response('', 204)]);
        $room = $this->room(7200, playing: false);
        WatchState::touch($room);

        $this->assertSame(RoomWatcher::SLEEPING_PAUSED_INTERVAL, app(RoomWatcher::class)->tick($room));
    }

    // ---- what counts as somebody doing something

    public function test_doing_something_ends_a_quiet_spell_and_wakes_the_watcher(): void
    {
        $room = $this->room(7200);

        $room->markActive();

        $this->assertSame('active', $room->fresh()->activityTier());
        $this->assertTrue(WatchState::takeWake($room), 'the watcher is told to look now, not when its slow schedule says');
        $this->assertFalse(WatchState::takeWake($room), 'once');
    }

    public function test_actions_within_half_a_minute_are_one_write_and_do_not_wake_again(): void
    {
        $room = $this->room(0);
        $before = $room->fresh()->last_activity_at;

        $this->travel(10)->seconds();
        $room->markActive();

        $this->assertEquals($before, $room->fresh()->last_activity_at);
        $this->assertFalse(WatchState::takeWake($room));
    }

    public function test_a_wake_a_command_or_the_due_time_each_make_a_room_due(): void
    {
        $room = $this->room(0);
        $watcher = app(RoomWatcher::class);

        $this->assertFalse($watcher->isDue($room, microtime(true) + 30), 'waiting for its schedule');
        $this->assertTrue($watcher->isDue($room, microtime(true) - 1), 'its time has come');
        $this->assertTrue($watcher->isDue($room, null), 'never looked at');

        WatchState::wake($room);
        $this->assertTrue($watcher->isDue($room, microtime(true) + 30), 'somebody woke it');
        $this->assertFalse($watcher->isDue($room, microtime(true) + 30), 'and only once');

        WatchState::expect($room, 'skip', 't1');
        $this->assertTrue($watcher->isDue($room, microtime(true) + 30), 'a command that needs confirming');
    }

    private function hostComponent(Room $room)
    {
        return Livewire::actingAs($this->host)->test(ShowRoom::class, ['room' => $room]);
    }

    public function test_pressing_a_control_counts(): void
    {
        Http::fake(['api.spotify.com/v1/me/player/queue*' => Http::response(['queue' => []]), 'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => [['id' => 'd', 'name' => 'x', 'type' => 'Computer', 'is_active' => true]]]), 'api.spotify.com/*' => Http::response('', 204)]);
        $room = $this->room(7200);

        $this->hostComponent($room)->call('pause');

        $this->assertSame('active', $room->fresh()->activityTier());
    }

    public function test_controls_that_write_no_log_line_count_too(): void
    {
        Http::fake(['api.spotify.com/v1/me/player/queue*' => Http::response(['queue' => []]), 'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => [['id' => 'd', 'name' => 'x', 'type' => 'Computer', 'is_active' => true]]]), 'api.spotify.com/*' => Http::response('', 204)]);

        foreach (['setVolume' => [40], 'seek' => [5000], 'toggleShuffle' => [], 'toggleRepeat' => []] as $action => $arguments) {
            $room = $this->room(7200);
            $this->hostComponent($room);   // opening it is itself activity, so put it back to sleep before the control
            \DB::table('rooms')->where('id', $room->id)->update(['last_activity_at' => now()->subSeconds(7200)]);
            $component = Livewire::actingAs($this->host)->test(ShowRoom::class, ['room' => $room->fresh()]);
            \DB::table('rooms')->where('id', $room->id)->update(['last_activity_at' => now()->subSeconds(7200)]);

            $component->call($action, ...$arguments);

            $this->assertSame('active', $room->fresh()->activityTier(), "{$action} is somebody doing something");
            \DB::table('rooms')->where('id', $room->id)->delete();
        }
    }

    public function test_a_host_setting_with_no_playback_in_it_counts_too(): void
    {
        $room = $this->room(7200);
        $component = $this->hostComponent($room);
        \DB::table('rooms')->where('id', $room->id)->update(['last_activity_at' => now()->subSeconds(7200)]);

        $component->call('togglePrivate');

        $this->assertSame('active', $room->fresh()->activityTier());
    }

    public function test_opening_the_room_counts_but_a_screen_refreshing_itself_does_not(): void
    {
        $room = $this->room(7200);
        WatchState::beat();

        $component = $this->hostComponent($room);
        $this->assertSame('active', $room->fresh()->activityTier(), 'opening it');

        \DB::table('rooms')->where('id', $room->id)->update(['last_activity_at' => now()->subSeconds(7200)]);
        $component->call('heartbeat')->call('heartbeat');
        $this->assertSame('idle', $room->fresh()->activityTier(), 'the screen polling itself is not a person');

        Livewire::test(PartyScreen::class, ['room' => $room->fresh()])->call('poll')->call('poll');
        $this->assertSame('active', $room->fresh()->activityTier(), 'putting the party screen up is, the polls after it are not');
    }

    public function test_a_party_screen_that_only_polls_does_not_count(): void
    {
        $room = $this->room(0);
        $component = Livewire::test(PartyScreen::class, ['room' => $room]);
        \DB::table('rooms')->where('id', $room->id)->update(['last_activity_at' => now()->subSeconds(7200)]);

        $component->call('poll')->call('poll');

        $this->assertSame('idle', $room->fresh()->activityTier());
    }

    public function test_a_guest_joining_counts(): void
    {
        $room = $this->room(7200);

        app(RoomMembership::class)->joinAsGuest($room, 'Newcomer');

        $this->assertSame('active', $room->fresh()->activityTier());
    }

    public function test_someone_waiting_to_be_let_in_does_not_wake_the_room(): void
    {
        $room = $this->room(7200);
        $room->update(['is_private' => true]);
        \DB::table('rooms')->where('id', $room->id)->update(['last_activity_at' => now()->subSeconds(7200)]);
        ['guest_token' => $token] = app(RoomMembership::class)->joinAsGuest($room->fresh(), 'Waiting');
        \DB::table('rooms')->where('id', $room->id)->update(['last_activity_at' => now()->subSeconds(7200)]);

        Livewire::withCookie(RoomMembership::cookieName($room), $token)->test(ShowRoom::class, ['room' => $room->fresh()]);

        $this->assertSame('idle', $room->fresh()->activityTier(), 'only people who are in count when it comes to opening the room');
        $this->assertNotNull(RoomMember::where('guest_token', $token)->first());
    }
}
