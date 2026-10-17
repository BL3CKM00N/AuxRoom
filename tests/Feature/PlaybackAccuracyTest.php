<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\Spotify\PlaybackSync;
use App\Services\Spotify\RoomWatcher;
use App\Services\Spotify\WatchState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** How exactly a room's position follows Spotify: milliseconds, the moment Spotify answered, small drifts, song ends, several rooms. */
class PlaybackAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private int $rooms = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function room(array $attributes = [], string $token = 'token'): Room
    {
        $host = User::factory()->create();
        SpotifyAccount::create([
            'user_id' => $host->id, 'client_id' => 'id', 'client_secret' => 'secret',
            'access_token' => $token, 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
        ]);

        $room = Room::create($attributes + [
            'invite_code' => sprintf('%06d-BBBBBB-CCCCCC', ++$this->rooms), 'host_id' => $host->id, 'playback_provider_id' => $host->id,
            'is_playing' => true, 'repeat_mode' => 'off', 'now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha',
            'now_playing_artist' => 'Nobody', 'now_playing_duration_ms' => 200000, 'now_playing_position_ms' => 0,
            'now_playing_started_at' => now(), 'now_playing_artists' => [], 'volume_percent' => 50, 'volume_supported' => true,
        ])->refresh();
        WatchState::markVerified($room);

        return $room;
    }

    private function state(array $overrides = []): array
    {
        return $overrides + [
            'is_playing' => true, 'progress_ms' => 10000, 'track_id' => 't1', 'device_id' => 'd1', 'name' => 'Alpha', 'artist' => 'Nobody',
            'artists' => [], 'explicit' => false, 'album_art_url' => null, 'duration_ms' => 200000, 'context_uri' => null,
            'shuffle_enabled' => false, 'smart_shuffle_enabled' => false, 'supports_volume' => true, 'volume_percent' => 50, 'repeat_mode' => 'off',
        ];
    }

    // ---- milliseconds

    public function test_the_start_time_is_kept_to_the_millisecond(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00.250'));
        $room = $this->room(['now_playing_started_at' => now()->subMilliseconds(10000)]);

        $this->assertSame(Carbon::parse('2026-10-14 12:00:00.250')->getTimestamp() * 1000 + 250 - 10000, $room->now_playing_started_ms);
        $this->assertSame(10000, $room->currentPositionMs(), 'not 9750 or 10250 from whole-second rounding');

        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:03.100'));
        $this->assertSame(12850, $room->fresh()->currentPositionMs());
        Carbon::setTestNow();
    }

    public function test_clearing_the_start_time_clears_the_milliseconds_too(): void
    {
        $room = $this->room();
        $room->update(['now_playing_started_at' => null, 'is_playing' => false, 'now_playing_position_ms' => 5000]);

        $this->assertNull($room->fresh()->now_playing_started_ms);
        $this->assertNull($room->fresh()->startedAtMs());
        $this->assertSame(5000, $room->fresh()->currentPositionMs());
    }

    public function test_a_row_from_before_the_milliseconds_still_works_to_the_second(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:10'));
        $room = $this->room(['now_playing_started_at' => Carbon::parse('2026-10-14 12:00:00')]);
        \DB::table('rooms')->where('id', $room->id)->update(['now_playing_started_ms' => null]);

        $this->assertSame(10000, $room->fresh()->currentPositionMs());
        $this->assertSame(Carbon::parse('2026-10-14 12:00:00')->getTimestamp() * 1000, $room->fresh()->startedAtMs());
        Carbon::setTestNow();
    }

    // ---- the moment Spotify answered

    public function test_the_position_is_pinned_to_when_spotify_answered_not_when_we_got_round_to_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00.000'));
        $room = $this->room(['is_playing' => false, 'now_playing_started_at' => null, 'now_playing_position_ms' => 0]);
        $nowMs = Carbon::now()->getPreciseTimestamp(3);

        // Spotify answered 300 ms ago and said 50 s into the song.
        app(PlaybackSync::class)->applyState($room, $this->state(['progress_ms' => 50000, 'fetched_at_ms' => $nowMs - 300]));

        $this->assertSame((int) round($nowMs - 300 - 50000), $room->fresh()->now_playing_started_ms);
        $this->assertSame(50300, $room->fresh()->currentPositionMs());
        Carbon::setTestNow();
    }

    // ---- small drifts and jumps

    private function playingAt10s(): Room
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:10.000'));

        return $this->room(['now_playing_started_at' => now()->subMilliseconds(10000)]);
    }

    public function test_a_tiny_difference_changes_nothing(): void
    {
        $room = $this->playingAt10s();
        $before = $room->now_playing_started_ms;
        Event::fake([\App\Events\RoomUpdated::class]);

        app(PlaybackSync::class)->applyState($room, $this->state(['progress_ms' => 10100, 'fetched_at_ms' => Carbon::now()->getPreciseTimestamp(3)]));

        $this->assertSame($before, $room->fresh()->now_playing_started_ms);
        Event::assertNotDispatched(\App\Events\RoomUpdated::class);
        Carbon::setTestNow();
    }

    public function test_a_small_drift_is_corrected_quietly_without_a_push(): void
    {
        $room = $this->playingAt10s();
        Event::fake([\App\Events\RoomUpdated::class]);

        app(PlaybackSync::class)->applyState($room, $this->state(['progress_ms' => 10500, 'fetched_at_ms' => Carbon::now()->getPreciseTimestamp(3)]));

        $this->assertSame(10500, $room->fresh()->currentPositionMs(), 'the server position now matches Spotify');
        Event::assertNotDispatched(\App\Events\RoomUpdated::class);
        Carbon::setTestNow();
    }

    public function test_a_jump_is_corrected_and_pushed_to_every_screen(): void
    {
        $room = $this->playingAt10s();
        Event::fake([\App\Events\RoomUpdated::class]);

        // 2 seconds off used to pass for "close enough" (the threshold was 3 s).
        app(PlaybackSync::class)->applyState($room, $this->state(['progress_ms' => 12000, 'fetched_at_ms' => Carbon::now()->getPreciseTimestamp(3)]));

        $this->assertSame(12000, $room->fresh()->currentPositionMs());
        Event::assertDispatched(\App\Events\RoomUpdated::class, fn ($event) => $event->reason === 'playback');
        Carbon::setTestNow();
    }

    public function test_a_paused_room_is_not_nudged(): void
    {
        $room = $this->room(['is_playing' => false, 'now_playing_started_at' => null, 'now_playing_position_ms' => 10000]);
        Event::fake([\App\Events\RoomUpdated::class]);

        app(PlaybackSync::class)->applyState($room, $this->state(['is_playing' => false, 'progress_ms' => 10400]));

        $this->assertNull($room->fresh()->now_playing_started_ms);
        Event::assertNotDispatched(\App\Events\RoomUpdated::class, fn ($e) => $e->reason === 'playback');
    }

    // ---- reading faster around the end of a song

    private function cadenceAt(int $positionMs, array $attributes = [], bool $watched = true): ?float
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00.000'));
        $room = $this->room($attributes + ['now_playing_started_at' => now()->subMilliseconds($positionMs)]);
        $watched ? WatchState::touch($room) : null;

        $cadence = app(RoomWatcher::class)->cadence($room);
        Carbon::setTestNow();

        return $cadence;
    }

    public function test_mid_song_the_room_is_read_at_the_normal_pace(): void
    {
        $this->assertSame(RoomWatcher::WATCHED_INTERVAL, $this->cadenceAt(100000));
    }

    public function test_the_last_second_and_a_half_and_the_two_after_are_read_fast(): void
    {
        $this->assertSame(0.3, $this->cadenceAt(198800), '1.2 s before the end');
        $this->assertSame(0.3, $this->cadenceAt(200000), 'at the end');
        $this->assertSame(0.3, $this->cadenceAt(201900), 'just after: Spotify moves on a little late');
        $this->assertSame(RoomWatcher::WATCHED_INTERVAL, $this->cadenceAt(203000), 'well after: the room is stale or repeating, so normal');
    }

    public function test_a_slow_cadence_is_shortened_so_the_next_read_lands_when_the_window_opens(): void
    {
        // Watched long ago: 5 s pace. 4 s left means the window opens in 2.5 s.
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00'));
        $room = $this->room(['now_playing_started_at' => now()->subMilliseconds(196000)]);
        WatchState::touch($room);
        $this->travel(100)->seconds();
        $room->update(['now_playing_started_at' => now()->subMilliseconds(196000)]);

        $this->assertEqualsWithDelta(2.5, app(RoomWatcher::class)->cadence($room->fresh()), 0.01);
        Carbon::setTestNow();
    }

    public function test_a_paused_room_or_one_on_repeat_has_no_end_window(): void
    {
        $this->assertSame(RoomWatcher::IDLE_WATCHED_INTERVAL, $this->cadenceAt(199000, ['is_playing' => false, 'now_playing_position_ms' => 199000]), 'paused: slower still, and no end window');
        $this->assertSame(RoomWatcher::WATCHED_INTERVAL, $this->cadenceAt(199000, ['repeat_mode' => 'track']));
    }

    public function test_an_unwatched_room_is_still_not_read_at_all(): void
    {
        $this->assertNull($this->cadenceAt(199000, [], watched: false));
    }

    // ---- several rooms at once

    private function twoRooms(): array
    {
        $a = $this->room([], 'token-a');
        $b = $this->room([], 'token-b');
        foreach ([$a, $b] as $room) {
            WatchState::touch($room);
        }

        return [$a, $b];
    }

    private function fakePlayer(): void
    {
        Http::fake([
            'api.spotify.com/v1/me/player/queue*' => Http::response(['queue' => []]),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => []]),
            'api.spotify.com/v1/me/player' => fn (Request $r) => Http::response([
                'is_playing' => true, 'progress_ms' => 1000, 'shuffle_state' => false, 'repeat_state' => 'off', 'context' => null,
                'device' => ['id' => 'd1', 'supports_volume' => true, 'volume_percent' => 50],
                'item' => ['id' => $r->hasHeader('Authorization', 'Bearer token-a') ? 'from-a' : 'from-b', 'name' => 'Song', 'duration_ms' => 100000, 'artists' => [['id' => 'x', 'name' => 'X']], 'album' => ['images' => []]],
            ]),
        ]);
    }

    private function playerReads(): int
    {
        return Http::recorded()->filter(fn ($pair) => $pair[0]->method() === 'GET' && str_ends_with(parse_url($pair[0]->url(), PHP_URL_PATH), '/me/player'))->count();
    }

    public function test_two_rooms_due_together_are_asked_at_once_with_their_own_logins(): void
    {
        [$a, $b] = $this->twoRooms();
        $this->fakePlayer();

        $answers = app(RoomWatcher::class)->prefetch(collect([$a, $b]));

        $this->assertSame([$a->id, $b->id], array_keys($answers));
        $this->assertSame(2, $this->playerReads());
        $tokens = Http::recorded()->map(fn ($pair) => $pair[0]->header('Authorization')[0] ?? '')->all();
        $this->assertContains('Bearer token-a', $tokens);
        $this->assertContains('Bearer token-b', $tokens);
    }

    public function test_each_room_gets_its_own_answer_and_asks_nothing_more(): void
    {
        [$a, $b] = $this->twoRooms();
        $this->fakePlayer();
        $watcher = app(RoomWatcher::class);

        $answers = $watcher->prefetch(collect([$a, $b]));
        $watcher->tick($a, $answers[$a->id]);
        $watcher->tick($b, $answers[$b->id]);

        $this->assertSame('from-a', $a->fresh()->now_playing_track_id);
        $this->assertSame('from-b', $b->fresh()->now_playing_track_id);
        $this->assertSame(2, $this->playerReads(), 'the ticks used the answers they were given');
    }

    public function test_one_room_alone_is_not_worth_a_pool(): void
    {
        [$a] = $this->twoRooms();
        $this->fakePlayer();

        $this->assertSame([], app(RoomWatcher::class)->prefetch(collect([$a])));
        $this->assertSame(0, $this->playerReads());
    }

    public function test_a_room_whose_request_failed_is_left_to_its_own_tick_and_the_other_still_works(): void
    {
        [$a, $b] = $this->twoRooms();
        Http::fake([
            'api.spotify.com/v1/me/player/queue*' => Http::response(['queue' => []]),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => []]),
            'api.spotify.com/v1/me/player' => function (Request $r) {
                if ($r->hasHeader('Authorization', 'Bearer token-b')) {
                    throw new ConnectionException('down');
                }

                return Http::response(['is_playing' => true, 'progress_ms' => 1000, 'shuffle_state' => false, 'repeat_state' => 'off', 'context' => null,
                    'device' => ['id' => 'd1', 'supports_volume' => true, 'volume_percent' => 50],
                    'item' => ['id' => 'from-a', 'name' => 'Song', 'duration_ms' => 100000, 'artists' => [], 'album' => ['images' => []]]]);
            },
        ]);
        $watcher = app(RoomWatcher::class);

        $answers = $watcher->prefetch(collect([$a, $b]));

        $this->assertSame([$a->id], array_keys($answers));
        $watcher->tick($a, $answers[$a->id]);
        $this->assertSame('from-a', $a->fresh()->now_playing_track_id);
        $this->assertGreaterThanOrEqual(1.0, $watcher->tick($b, null), 'the failure takes the normal backoff');
    }

    public function test_an_account_that_needs_reconnecting_does_not_stop_the_other_room(): void
    {
        [$a, $b] = $this->twoRooms();
        $b->playbackProvider->spotifyAccount->update(['needs_reconnect_at' => now()]);
        $this->fakePlayer();

        $answers = app(RoomWatcher::class)->prefetch(collect([$a, $b]));

        $this->assertArrayNotHasKey($b->id, $answers);
    }
}
