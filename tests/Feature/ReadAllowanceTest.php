<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\Spotify\RoomWatcher;
use App\Services\Spotify\SpotifyBudget;
use App\Services\Spotify\SpotifyClientFactory;
use App\Services\Spotify\SpotifyRequestFailed;
use App\Services\Spotify\WatchState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** However many rooms and screens there are, one Spotify app only sends so many reads a minute. */
class ReadAllowanceTest extends TestCase
{
    use RefreshDatabase;

    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake([
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => [['id' => 'd1', 'name' => 'Laptop', 'type' => 'Computer', 'is_active' => true]]]),
            'api.spotify.com/v1/me/player/queue*' => Http::response(['queue' => []]),
            'api.spotify.com/*' => Http::response('', 204),
        ]);
    }

    private function room(string $clientId = 'app-one'): Room
    {
        $host = User::factory()->create();
        SpotifyAccount::create([
            'user_id' => $host->id, 'client_id' => $clientId, 'client_secret' => 'secret',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
        ]);

        return Room::create(['invite_code' => sprintf('%06d-BBBBBB-CCCCCC', ++$this->made), 'host_id' => $host->id, 'playback_provider_id' => $host->id, 'is_playing' => true])->refresh();
    }

    private function client(Room $room)
    {
        return app(SpotifyClientFactory::class)->forRoom($room);
    }

    private function sent(): int
    {
        return Http::recorded()->count();
    }

    public function test_reads_go_out_up_to_the_allowance_and_then_wait_for_the_next_minute(): void
    {
        $room = $this->room();
        $client = $this->client($room);

        for ($i = 0; $i < SpotifyBudget::READS_PER_MINUTE; $i++) {
            $client->getPlaybackState();
        }
        $this->assertSame(SpotifyBudget::READS_PER_MINUTE, $this->sent());

        try {
            $client->getPlaybackState();
            $this->fail('the allowance is gone');
        } catch (SpotifyRequestFailed $e) {
            $this->assertSame(429, $e->status());
            $this->assertGreaterThanOrEqual(1, $e->retryAfter);
            $this->assertLessThanOrEqual(60, $e->retryAfter, 'only until the next minute begins');
        }
        $this->assertSame(SpotifyBudget::READS_PER_MINUTE, $this->sent(), 'the denied read never left the server');

        $this->travel(61)->seconds();
        $client->getPlaybackState();
        $this->assertSame(SpotifyBudget::READS_PER_MINUTE + 1, $this->sent());
    }

    public function test_commands_are_never_held_back(): void
    {
        $room = $this->room();
        $client = $this->client($room);

        for ($i = 0; $i < SpotifyBudget::READS_PER_MINUTE + 20; $i++) {
            try {
                $client->getPlaybackState();
            } catch (SpotifyRequestFailed) {
            }
        }
        $before = $this->sent();

        $this->assertTrue($client->pause());
        $this->assertTrue($client->skipToNext());

        $this->assertSame($before + 2, $this->sent());
    }

    public function test_the_allowance_belongs_to_the_spotify_app(): void
    {
        $one = $this->client($this->room('app-one'));
        $other = $this->client($this->room('app-two'));
        $sameApp = $this->client($this->room('app-one'));

        for ($i = 0; $i < SpotifyBudget::READS_PER_MINUTE; $i++) {
            $one->getPlaybackState();
        }

        $this->expectException(SpotifyRequestFailed::class);
        try {
            $sameApp->getPlaybackState();
        } finally {
            $this->assertNull($other->getPlaybackState(), 'a second app has its own allowance and goes through');
        }
    }

    public function test_the_allowance_is_shared_by_whatever_asks(): void
    {
        $room = $this->room();
        $account = $room->playbackProvider->spotifyAccount;

        for ($i = 0; $i < SpotifyBudget::READS_PER_MINUTE; $i++) {
            $this->assertNull(SpotifyBudget::takeRead($account));
        }

        $this->assertNotNull(SpotifyBudget::takeRead($account));
    }

    public function test_the_watcher_waits_out_the_minute_instead_of_failing_or_asking(): void
    {
        $room = $this->room();
        WatchState::touch($room);
        $account = $room->playbackProvider->spotifyAccount;
        for ($i = 0; $i < SpotifyBudget::READS_PER_MINUTE; $i++) {
            SpotifyBudget::takeRead($account);
        }
        $before = $this->sent();

        $wait = app(RoomWatcher::class)->tick($room);

        $this->assertGreaterThanOrEqual(1.0, $wait);
        $this->assertLessThanOrEqual(60.0, $wait, 'a minute at most, not hours');
        $this->assertSame($before, $this->sent());
    }

    public function test_rooms_that_are_due_together_do_not_all_ask_when_the_allowance_is_gone(): void
    {
        $a = $this->room('app-one');
        $b = $this->room('app-one');
        $account = $a->playbackProvider->spotifyAccount;
        for ($i = 0; $i < SpotifyBudget::READS_PER_MINUTE; $i++) {
            SpotifyBudget::takeRead($account);
        }
        $before = $this->sent();

        $answers = app(RoomWatcher::class)->prefetch(collect([$a, $b]));

        $this->assertSame([], $answers);
        $this->assertSame($before, $this->sent());
    }

    public function test_a_failed_read_keeps_showing_the_last_devices_instead_of_none(): void
    {
        // Ten seconds into a minute, so the wait below stays inside the same minute's allowance.
        $this->travelTo(now()->startOfMinute()->addSeconds(10));
        $room = $this->room();
        $client = $this->client($room);

        $this->assertCount(1, $client->getDevices());

        // The allowance is gone, and the short cache has run out.
        $account = $room->playbackProvider->spotifyAccount;
        for ($i = 0; $i < SpotifyBudget::READS_PER_MINUTE; $i++) {
            SpotifyBudget::takeRead($account);
        }
        $this->travel(6)->seconds();   // past the five seconds a device list is kept for

        $this->assertCount(1, $this->client($room)->getDevices(), 'not being able to ask is not "no devices"');
    }

    public function test_a_failed_read_keeps_the_last_queue(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.spotify.com/v1/me/player/queue*' => Http::response(['queue' => [['id' => 'q1', 'uri' => 'spotify:track:q1', 'name' => 'Song', 'type' => 'track', 'duration_ms' => 1000, 'artists' => [['name' => 'X']], 'album' => ['images' => []]]]])]);
        $room = $this->room();
        $client = $this->client($room);
        $this->assertCount(1, $client->getQueue(fresh: true));

        $account = $room->playbackProvider->spotifyAccount;
        for ($i = 0; $i < SpotifyBudget::READS_PER_MINUTE; $i++) {
            SpotifyBudget::takeRead($account);
        }

        $this->assertCount(1, $this->client($room)->getQueue(fresh: true));
    }

    public function test_with_nothing_read_before_a_failed_read_is_simply_empty(): void
    {
        $room = $this->room();
        $account = $room->playbackProvider->spotifyAccount;
        for ($i = 0; $i < SpotifyBudget::READS_PER_MINUTE; $i++) {
            SpotifyBudget::takeRead($account);
        }

        $this->assertSame([], $this->client($room)->getDevices());
        $this->assertSame([], $this->client($room)->getQueue(fresh: true));
    }
}
