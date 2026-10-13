<?php

namespace Tests\Feature;

use App\Events\RoomUpdated;
use App\Models\Room;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\RoomMembership;
use App\Services\Spotify\RoomWatcher;
use App\Services\Spotify\WatchState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A push that goes missing is noticed (numbered pushes), and a queue change made outside AuxRoom is pushed too. */
class LostPushTest extends TestCase
{
    use RefreshDatabase;

    private function room(string $code = 'AAAAAA-BBBBBB-CCCCCC'): Room
    {
        $host = User::factory()->create();
        SpotifyAccount::create([
            'user_id' => $host->id, 'client_id' => 'id', 'client_secret' => 'secret',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
        ]);
        $room = Room::create([
            'invite_code' => $code, 'host_id' => $host->id, 'playback_provider_id' => $host->id, 'is_playing' => true,
            'now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha', 'now_playing_artist' => 'Nobody', 'now_playing_duration_ms' => 200000,
            'now_playing_position_ms' => 0, 'now_playing_started_at' => now(), 'volume_percent' => 50, 'volume_supported' => true,
            'now_playing_artists' => [], 'repeat_mode' => 'off',
        ])->refresh();
        app(RoomMembership::class)->joinAsHost($room);
        WatchState::markVerified($room);

        return $room;
    }

    public function test_every_push_of_a_room_carries_the_next_number(): void
    {
        Cache::flush();
        $room = $this->room();   // joining already sent one
        $before = (int) Cache::get("room:seq:{$room->id}");
        Event::fake([RoomUpdated::class]);

        RoomUpdated::broadcastFor($room);
        RoomUpdated::broadcastFor($room, 'playback');
        RoomUpdated::broadcastFor($room, 'queue');

        $seqs = Event::dispatched(RoomUpdated::class)->map(fn (array $args) => $args[0]->seq)->values()->all();
        $this->assertSame([$before + 1, $before + 2, $before + 3], $seqs, 'no gaps, so a screen can tell when one is missing');
    }

    public function test_rooms_are_numbered_separately(): void
    {
        Cache::flush();
        $a = $this->room('AAAAAA-BBBBBB-CCCCCC');
        $b = $this->room('DDDDDD-EEEEEE-FFFFFF');
        $aBefore = (int) Cache::get("room:seq:{$a->id}");
        $bBefore = (int) Cache::get("room:seq:{$b->id}");
        Event::fake([RoomUpdated::class]);

        RoomUpdated::broadcastFor($a);
        RoomUpdated::broadcastFor($a);
        RoomUpdated::broadcastFor($b);

        $seqs = Event::dispatched(RoomUpdated::class)->map(fn (array $args) => [$args[0]->room->id, $args[0]->seq])->values()->all();
        $this->assertSame([[$a->id, $aBefore + 1], [$a->id, $aBefore + 2], [$b->id, $bBefore + 1]], $seqs);
    }

    public function test_the_number_goes_out_in_the_payload(): void
    {
        Cache::flush();
        $room = $this->room();

        $event = new RoomUpdated($room, 'playback', 7);

        $this->assertSame(['reason' => 'playback', 'seq' => 7], $event->broadcastWith());
        $this->assertSame('RoomUpdated', $event->broadcastAs());
    }

    private function fakeSpotify(array $queueIds): void
    {
        Http::fake([
            'api.spotify.com/v1/me/player/queue*' => Http::response(['queue' => array_map(fn ($id) => ['id' => $id, 'uri' => "spotify:track:{$id}", 'name' => "Song {$id}", 'type' => 'track', 'duration_ms' => 1000, 'artists' => [['name' => 'X']], 'album' => ['images' => []]], $queueIds)]),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => []]),
            'api.spotify.com/v1/me/player' => Http::response([
                'is_playing' => true, 'progress_ms' => 0, 'shuffle_state' => false, 'repeat_state' => 'off', 'context' => null,
                'device' => ['id' => 'd1', 'supports_volume' => true, 'volume_percent' => 50],
                'item' => ['id' => 't1', 'name' => 'Alpha', 'duration_ms' => 200000, 'artists' => [], 'album' => ['images' => []]],
            ]),
        ]);
    }

    private function queuePushes(): int
    {
        return Event::dispatched(RoomUpdated::class, fn (RoomUpdated $e) => $e->reason === 'queue')->count();
    }

    public function test_a_queue_change_the_watcher_sees_is_pushed_to_the_screens(): void
    {
        Cache::flush();
        $room = $this->room();
        WatchState::putQueue($room, [['id' => 'q1', 'uri' => 'spotify:track:q1', 'name' => 'Song q1', 'artist' => 'X', 'album_art_url' => null, 'duration_ms' => 1000]]);
        Cache::put("watch:queue:track:{$room->id}", 't0', 3600);   // the track changed since the queue was stored
        $this->fakeSpotify(['q2']);
        Event::fake([RoomUpdated::class]);

        app(RoomWatcher::class)->tick($room);

        $this->assertSame(1, $this->queuePushes(), 'screens that re-rendered for the track change may have had the old queue');
    }

    public function test_an_unchanged_queue_is_not_pushed(): void
    {
        Cache::flush();
        $room = $this->room();
        WatchState::putQueue($room, [['id' => 'q1', 'uri' => 'spotify:track:q1', 'name' => 'Song q1', 'artist' => 'X', 'album_art_url' => null, 'duration_ms' => 1000]]);
        Cache::put("watch:queue:track:{$room->id}", 't0', 3600);
        $this->fakeSpotify(['q1']);
        Event::fake([RoomUpdated::class]);

        app(RoomWatcher::class)->tick($room);

        $this->assertSame(0, $this->queuePushes());
    }

    public function test_the_first_ever_queue_read_is_not_a_change(): void
    {
        Cache::flush();
        $room = $this->room();
        $this->fakeSpotify(['q1']);
        Event::fake([RoomUpdated::class]);

        app(RoomWatcher::class)->tick($room);

        $this->assertSame(0, $this->queuePushes());
    }

    public function test_the_room_pages_carry_the_guard_and_the_connection_checks_are_tighter(): void
    {
        $room = $this->room();

        $this->actingAs($room->host)->get(route('dashboard'))->assertSee("roomGuard('{$room->invite_code}', 'heartbeat')", false);
        $this->get(route('rooms.party', $room))->assertSee("roomGuard('{$room->invite_code}', 'poll')", false);

        $echo = file_get_contents(base_path('resources/js/echo.js'));
        $this->assertStringContainsString('activityTimeout: 12000', $echo);
        $this->assertStringContainsString('pongTimeout: 8000', $echo);
        $this->assertStringContainsString(".listen('.RoomUpdated'", file_get_contents(base_path('resources/js/alpine/room-guard.js')));
    }
}
