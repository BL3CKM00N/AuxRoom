<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** A room opened for the first time (or after nobody watched it) shows what Spotify is playing at once. */
class FirstLookTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = User::factory()->create();
        SpotifyAccount::create([
            'user_id' => $this->host->id, 'client_id' => 'id', 'client_secret' => 'secret', 'access_token' => 'token',
            'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
        ]);
        $this->room = Room::create(['invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $this->host->id, 'playback_provider_id' => $this->host->id])->refresh();
        app(RoomMembership::class)->joinAsHost($this->room);

        Cache::flush();
        Http::fake([
            'api.spotify.com/v1/me/player/queue*' => Http::response(['queue' => []]),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => [['id' => 'd1', 'name' => 'Laptop', 'type' => 'Computer', 'is_active' => true, 'supports_volume' => true]]]),
            'api.spotify.com/v1/me/player' => Http::response([
                'is_playing' => true, 'progress_ms' => 42000, 'shuffle_state' => false, 'repeat_state' => 'off',
                'device' => ['id' => 'd1', 'supports_volume' => true, 'volume_percent' => 50], 'context' => null,
                'item' => ['id' => 'tr1', 'name' => 'Already Playing Song', 'duration_ms' => 200000, 'explicit' => false,
                    'artists' => [['id' => 'a1', 'name' => 'Some Band']], 'album' => ['images' => []]],
            ]),
        ]);
    }

    private function playerReads(): int
    {
        return Http::recorded()->filter(fn ($pair) => $pair[0]->method() === 'GET' && str_ends_with(parse_url($pair[0]->url(), PHP_URL_PATH), '/me/player'))->count();
    }

    public function test_a_new_room_shows_the_song_that_is_already_playing_on_its_first_render(): void
    {
        $this->actingAs($this->host)->get(route('dashboard'))->assertOk()->assertSee('Already Playing Song')->assertSee('Some Band');
        $this->assertSame('tr1', $this->room->fresh()->now_playing_track_id);
    }

    public function test_a_guest_opening_a_room_nobody_watched_sees_it_too(): void
    {
        ['guest_token' => $token] = app(RoomMembership::class)->joinAsGuest($this->room, 'Guest');
        \App\Models\RoomMember::where('guest_token', $token)->update(['approved_at' => now()]);

        Livewire::withCookie(RoomMembership::cookieName($this->room), $token)->test(ShowRoom::class, ['room' => $this->room])->assertSee('Already Playing Song');
    }

    public function test_a_waiting_guest_does_not_trigger_a_read(): void
    {
        ['guest_token' => $token] = app(RoomMembership::class)->joinAsGuest($this->room, 'Guest');

        Livewire::withCookie(RoomMembership::cookieName($this->room), $token)->test(ShowRoom::class, ['room' => $this->room]);

        $this->assertSame(0, $this->playerReads(), 'someone not let in yet must not spend the host\'s Spotify quota');
    }

    public function test_the_party_screen_shows_it_too(): void
    {
        Livewire::test(PartyScreen::class, ['room' => $this->room])->assertSee('Already Playing Song');
    }

    public function test_a_room_the_watcher_keeps_fresh_is_not_read_again_on_open(): void
    {
        WatchState::markVerified($this->room);

        $this->actingAs($this->host)->get(route('dashboard'))->assertOk();

        $this->assertSame(0, $this->playerReads());
    }

    public function test_opening_twice_in_a_row_reads_spotify_once(): void
    {
        $this->actingAs($this->host)->get(route('dashboard'));
        Cache::forget("watch:verified:{$this->room->id}");
        $this->actingAs($this->host)->get(route('dashboard'));

        $this->assertSame(1, $this->playerReads());
    }

    public function test_a_stale_room_is_read_again_when_opened(): void
    {
        WatchState::markVerified($this->room);
        $this->travel(WatchState::HEARTBEAT_TTL)->seconds();

        $this->actingAs($this->host)->get(route('dashboard'))->assertSee('Already Playing Song');
        $this->assertSame(1, $this->playerReads());
    }

    public function test_the_watcher_and_a_sync_both_count_as_a_recent_look(): void
    {
        $this->assertNull(WatchState::verifiedAge($this->room));

        app(RoomWatcher::class)->tick($this->room);

        $this->assertSame(0, WatchState::verifiedAge($this->room));
    }

    public function test_the_pages_re_read_the_room_when_the_live_connection_comes_up(): void
    {
        $dashboard = $this->actingAs($this->host)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('realtime-connected.window="$wire.heartbeat()"', $dashboard);

        $party = $this->get(route('rooms.party', $this->room))->getContent();
        $this->assertStringContainsString('realtime-connected.window="$wire.poll()"', $party);

        $this->assertStringContainsString("'realtime-connected'", file_get_contents(base_path('resources/js/echo.js')));
    }
}
