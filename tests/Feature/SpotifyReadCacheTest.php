<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\Spotify\SpotifyWebApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpotifyReadCacheTest extends TestCase
{
    use RefreshDatabase;

    private function client(): SpotifyWebApiClient
    {
        $user = User::factory()->create();
        $account = SpotifyAccount::create([
            'user_id' => $user->id,
            'client_id' => 'id',
            'client_secret' => 'secret',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
        ]);

        return new SpotifyWebApiClient($account);
    }

    private function fakeSpotify(): void
    {
        Http::fake([
            'api.spotify.com/v1/me/player/queue' => Http::response(['queue' => [
                ['type' => 'track', 'id' => 't1', 'uri' => 'spotify:track:t1', 'name' => 'One', 'artists' => [['name' => 'A']], 'album' => ['images' => []], 'duration_ms' => 1000],
            ]]),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => [
                ['id' => 'd1', 'name' => 'Speaker', 'type' => 'Speaker', 'is_active' => true],
            ]]),
            'api.spotify.com/v1/me/player/*' => Http::response('', 204),
        ]);
    }

    private function hits(string $needle): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'GET' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), $needle))->count();
    }

    public function test_many_queue_reads_share_one_spotify_request(): void
    {
        $this->fakeSpotify();
        $client = $this->client();

        foreach (range(1, 20) as $_) {
            $this->assertSame('One', $client->getQueue()[0]['name']);
        }

        $this->assertSame(1, $this->hits('/queue'));
    }

    public function test_writes_invalidate_the_cached_queue(): void
    {
        $this->fakeSpotify();
        $client = $this->client();

        $client->getQueue();
        $client->addToPlaybackQueue('spotify:track:t2');
        $client->getQueue();

        $this->assertSame(2, $this->hits('/queue'), 'a read after a write must hit Spotify again');
    }

    public function test_default_device_read_is_always_live_but_display_reads_reuse_it(): void
    {
        $this->fakeSpotify();
        $client = $this->client();

        $client->getDevices();
        $client->getDevices();
        $this->assertSame(2, $this->hits('/devices'), 'command paths must never see a cached device list');

        $client->getDevices(allowCached: true);
        $client->getDevices(allowCached: true);
        $this->assertSame(2, $this->hits('/devices'), 'display reads should reuse the live read just made');
    }

    public function test_cache_is_scoped_per_spotify_account(): void
    {
        $this->fakeSpotify();
        $a = $this->client();
        $b = $this->client();

        $a->getQueue();
        $b->getQueue();

        $this->assertSame(2, $this->hits('/queue'));
        Cache::flush();
    }

    public function test_join_submissions_are_rate_limited(): void
    {
        $host = User::factory()->create();
        Room::create(['invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $host->id, 'playback_provider_id' => $host->id]);

        foreach (range(1, 10) as $_) {
            $this->post('/join', ['name' => 'G', 'invite_code' => 'AAAAAA-BBBBBB-CCCCCC'])->assertRedirect();
        }

        $this->post('/join', ['name' => 'G', 'invite_code' => 'AAAAAA-BBBBBB-CCCCCC'])->assertStatus(429);
    }
}
