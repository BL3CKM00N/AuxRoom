<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\PartyScreen;
use App\Livewire\Dashboard\ShowRoom;
use App\Models\Room;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\RoomMembership;
use App\Services\Spotify\CommandFailure;
use App\Services\Spotify\RoomWatcher;
use App\Services\Spotify\SpotifyBudget;
use App\Services\Spotify\SpotifyClientFactory;
use App\Services\Spotify\SpotifyRequestFailed;
use App\Services\Spotify\WatchState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spotify limits each Spotify app, and when the playback quota runs out it blocks those endpoints for hours.
 * Asking again does nothing, and "no device found" would be a lie: AuxRoom stays quiet and says what is going on.
 */
class SpotifyQuotaTest extends TestCase
{
    use RefreshDatabase;

    private Room $room;

    private SpotifyAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $host = User::factory()->create();
        $this->account = SpotifyAccount::create([
            'user_id' => $host->id, 'client_id' => 'app-one', 'client_secret' => 'secret',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
        ]);
        $this->room = Room::create([
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $host->id, 'playback_provider_id' => $host->id,
            'is_playing' => true, 'now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha', 'now_playing_artist' => 'Nobody',
            'now_playing_duration_ms' => 200000, 'now_playing_position_ms' => 0, 'now_playing_started_at' => now(), 'playback_inactive_at' => now(),
        ])->refresh();
        app(RoomMembership::class)->joinAsHost($this->room);
        WatchState::markVerified($this->room);
    }

    private function client()
    {
        return app(SpotifyClientFactory::class)->forRoom($this->room);
    }

    /** A new HTTP fake: the first matching stub wins, so an earlier one would otherwise keep answering. */
    private function freshHttp(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
    }

    private function fakeQuota(int $retryAfter = 120): void
    {
        $this->freshHttp();
        Http::fake([
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => []]]),
            'api.spotify.com/v1/me' => Http::response(['id' => 'x']),
            'api.spotify.com/*' => Http::response(['error' => ['status' => 429, 'message' => 'Too many requests', 'reason' => 'QUOTA_EXCEEDED']], 429, ['Retry-After' => (string) $retryAfter]),
        ]);
    }

    private function sentCount(): int
    {
        return Http::recorded()->count();
    }

    private function block(int $seconds = 7200): void
    {
        $this->fakeQuota($seconds);
        try {
            $this->client()->getPlaybackState();
        } catch (SpotifyRequestFailed) {
        }
        $this->freshHttp();
        Http::fake();   // anything sent from here on is a request that should not have been made
    }

    public function test_a_429_is_remembered_for_as_long_as_spotify_asked_and_nothing_more_is_sent(): void
    {
        $this->fakeQuota(120);

        try {
            $this->client()->getPlaybackState();
            $this->fail('the first answer is a failure');
        } catch (SpotifyRequestFailed $e) {
            $this->assertSame(429, $e->status());
            $this->assertSame(120, $e->retryAfter);
        }

        try {
            $this->client()->getPlaybackState();
        } catch (SpotifyRequestFailed $e) {
            $this->assertSame(429, $e->status());
            $this->assertLessThanOrEqual(120, $e->retryAfter, 'the wait it reports is what is left');
        }

        $this->assertSame(1, $this->sentCount(), 'the second look never left the server');
    }

    public function test_every_player_endpoint_is_quiet_but_search_still_works(): void
    {
        $this->fakeQuota(600);
        try {
            $this->client()->getPlaybackState();
        } catch (SpotifyRequestFailed) {
        }
        $before = $this->sentCount();

        $this->assertSame([], $this->client()->getDevices());
        $this->assertSame([], $this->client()->getQueue(fresh: true));
        $this->assertFalse($this->client()->pause());
        $this->assertFalse($this->client()->skipToNext());
        $this->assertSame($before, $this->sentCount(), 'devices, queue and commands wait too');

        $this->client()->search('anything');
        $this->assertSame($before + 1, $this->sentCount(), 'search is a different quota and still goes out');
    }

    public function test_a_command_during_the_wait_says_why(): void
    {
        $this->block();

        $this->assertFalse($this->client()->pause());

        $failure = app(CommandFailure::class);
        $this->assertTrue($failure->rateLimited());
        $this->assertStringContainsString('limiting', $failure->message('generic'));
        $this->assertSame(0, $this->sentCount());
    }

    public function test_the_block_belongs_to_the_spotify_app_not_to_one_login(): void
    {
        $this->block();

        $sameApp = SpotifyAccount::create([
            'user_id' => User::factory()->create()->id, 'client_id' => 'app-one', 'client_secret' => 'secret',
            'access_token' => 't', 'refresh_token' => 'r', 'token_expires_at' => now()->addHour(),
        ]);
        $otherApp = SpotifyAccount::create([
            'user_id' => User::factory()->create()->id, 'client_id' => 'app-two', 'client_secret' => 'secret',
            'access_token' => 't', 'refresh_token' => 'r', 'token_expires_at' => now()->addHour(),
        ]);

        $this->assertNotNull(SpotifyBudget::playerRemaining($sameApp), 'Spotify counts the app');
        $this->assertNull(SpotifyBudget::playerRemaining($otherApp), 'a second Spotify app has its own quota');
    }

    public function test_the_wait_has_sensible_limits_and_other_errors_do_not_block(): void
    {
        $account = $this->account;
        $respond = fn (int $status, ?string $retry) => new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response($status, $retry === null ? [] : ['Retry-After' => $retry]));

        SpotifyBudget::noteResponse($account, 'https://api.spotify.com/v1/me/player', $respond(500, null));
        SpotifyBudget::noteResponse($account, 'https://api.spotify.com/v1/me/player', $respond(404, null));
        $this->assertNull(SpotifyBudget::playerRemaining($account));

        SpotifyBudget::noteResponse($account, 'https://api.spotify.com/v1/me/player', $respond(429, null));
        $this->assertEqualsWithDelta(30, SpotifyBudget::playerRemaining($account), 2, 'no Retry-After: a short pause');

        Cache::flush();
        SpotifyBudget::noteResponse($account, 'https://api.spotify.com/v1/me/player', $respond(429, '9999999'));
        $this->assertEqualsWithDelta(86400, SpotifyBudget::playerRemaining($account), 2, 'never more than a day');
    }

    public function test_it_asks_again_once_the_wait_is_over(): void
    {
        $this->block(300);

        $this->travel(301)->seconds();
        $this->freshHttp();
        Http::fake(['api.spotify.com/v1/me/player' => Http::response('', 204)]);

        $this->assertNull($this->client()->getPlaybackState());
        $this->assertSame(1, $this->sentCount());
    }

    public function test_the_room_says_spotify_is_limiting_it_instead_of_no_device(): void
    {
        $this->room->update(['is_playing' => false, 'playback_inactive_at' => now(), 'now_playing_track_id' => null, 'now_playing_name' => null]);
        $this->block(7200);

        $html = Livewire::actingAs($this->room->host)->test(ShowRoom::class, ['room' => $this->room])->html();

        $this->assertStringContainsString('Spotify is limiting AuxRoom for about 2 h', $html);
        $this->assertStringContainsString('Waiting for Spotify', $html);
        $this->assertStringNotContainsString('Nothing&#039;s playing anywhere', $html);
        $this->assertStringNotContainsString('No device found', $html);
    }

    public function test_the_party_screen_says_it_too(): void
    {
        $room = $this->room->fresh();
        $room->update(['now_playing_track_id' => null, 'now_playing_name' => null]);
        $this->block(7200);

        Livewire::test(PartyScreen::class, ['room' => $room->fresh()])
            ->assertSee('Spotify is limiting AuxRoom')->assertSee('Waiting for Spotify')->assertSee('The music keeps playing')
            ->assertDontSee("Nothing's playing anywhere")->assertDontSee('No device found');
    }

    public function test_without_a_limit_a_room_with_no_device_still_says_so(): void
    {
        $this->room->update(['is_playing' => false, 'playback_inactive_at' => now()]);
        $this->freshHttp();
        Http::fake(['api.spotify.com/v1/me/player/devices' => Http::response(['devices' => []]), 'api.spotify.com/*' => Http::response('', 204)]);

        Livewire::actingAs($this->room->host)->test(ShowRoom::class, ['room' => $this->room])->assertSee("Nothing's playing anywhere");
    }

    public function test_pressing_a_control_during_the_wait_is_explained_and_sends_nothing(): void
    {
        $this->block();
        $room = $this->room->fresh();
        $room->update(['playback_inactive_at' => null]);

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->call('pause')->assertSet('controlErrorCode', 'SP-QUOTA');

        $this->assertSame(0, $this->sentCount());
        $this->assertNotNull(\App\Support\Errors\ErrorCatalog::find('SP-QUOTA'));
    }

    public function test_the_watcher_waits_exactly_as_long_as_it_was_told_without_asking(): void
    {
        $this->block(7200);
        WatchState::touch($this->room);

        $wait = app(RoomWatcher::class)->tick($this->room);

        $this->assertGreaterThan(7000, $wait);
        $this->assertSame(0, $this->sentCount());
    }

    public function test_health_reports_the_limit_and_the_hourly_requests(): void
    {
        config(['broadcasting.default' => 'log']);
        WatchState::beat();
        $this->freshHttp();
        Http::fake(['api.spotify.com/*' => Http::response('', 204)]);
        $this->client()->getPlaybackState();
        $this->client()->getPlaybackState();

        $this->getJson('/health/watcher')->assertOk()->assertJson(['checks' => ['spotify' => ['ok' => true, 'limited_seconds' => null, 'calls_this_hour' => 2]]]);

        $this->block(5400);
        WatchState::beat();

        $this->getJson('/health/watcher')->assertStatus(503)->assertJson(['ok' => false, 'checks' => ['spotify' => ['ok' => false, 'calls_this_hour' => 3]]]);
    }

    public function test_the_wait_is_written_in_words(): void
    {
        $this->assertSame('a few seconds', SpotifyBudget::humanize(20));
        $this->assertSame('12 min', SpotifyBudget::humanize(12 * 60));
        $this->assertSame('2 h', SpotifyBudget::humanize(7200));
        $this->assertSame('11 h 45 min', SpotifyBudget::humanize(42300));
        $this->assertSame('1 h', SpotifyBudget::humanize(3599));
    }
}
