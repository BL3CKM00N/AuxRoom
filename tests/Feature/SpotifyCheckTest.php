<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\ShowRoom;
use App\Models\Room;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\RoomMembership;
use App\Services\Spotify\SpotifyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class SpotifyCheckTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'ZZ-very-secret-token-123';

    private function room(array $account = []): Room
    {
        $host = User::factory()->create();
        SpotifyAccount::create($account + [
            'user_id' => $host->id, 'client_id' => 'cid', 'client_secret' => self::SECRET,
            'access_token' => self::SECRET, 'refresh_token' => self::SECRET, 'token_expires_at' => now()->addHour(),
        ]);
        $room = Room::create(['invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $host->id, 'playback_provider_id' => $host->id])->refresh();
        app(RoomMembership::class)->joinAsHost($room);

        return $room;
    }

    /** @param array<string, mixed> $overrides profile / devices / state ('state' => null means 204) */
    private function spotify(array $overrides = []): void
    {
        $profile = $overrides['profile'] ?? ['id' => 'u1', 'display_name' => 'Daan', 'product' => 'premium'];
        $devices = $overrides['devices'] ?? [['id' => 'd1', 'name' => 'MacBook', 'type' => 'Computer', 'is_active' => true, 'supports_volume' => true]];
        $state = array_key_exists('state', $overrides) ? $overrides['state'] : [
            'is_playing' => true, 'progress_ms' => 1000, 'shuffle_state' => false, 'repeat_state' => 'off', 'context' => null,
            'device' => ['id' => 'd1', 'supports_volume' => true, 'volume_percent' => 50],
            'item' => ['id' => 't1', 'name' => 'Alpha Anthem', 'duration_ms' => 180000, 'artists' => [['name' => 'Nobody']], 'album' => ['images' => []]],
        ];

        Http::fake([
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => $devices]),
            'api.spotify.com/v1/me/player/queue' => Http::response(['queue' => []]),
            'api.spotify.com/v1/me/player' => $state === null ? Http::response('', 204) : Http::response($state),
            'api.spotify.com/v1/me' => $overrides['profileStatus'] ?? false ? Http::response('', $overrides['profileStatus']) : Http::response($profile),
        ]);
    }

    /** @return array<string, array> rows keyed by id */
    private function rows(Room $room): array
    {
        return collect(app(SpotifyCheck::class)->run($room))->keyBy('id')->all();
    }

    public function test_everything_healthy_is_all_green_and_never_exposes_a_secret(): void
    {
        $room = $this->room();
        $this->spotify();

        $rows = $this->rows($room);

        foreach (['credentials', 'connection', 'premium', 'devices', 'playback'] as $id) {
            $this->assertSame('ok', $rows[$id]['status'], $id);
        }
        $this->assertStringContainsString('Daan', $rows['connection']['detail']);
        $this->assertStringContainsString('MacBook', $rows['devices']['detail']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($rows));
    }

    public function test_a_free_account_fails_the_premium_check_with_its_guide(): void
    {
        $room = $this->room();
        $this->spotify(['profile' => ['id' => 'u1', 'display_name' => 'Daan', 'product' => 'free']]);

        $this->assertSame(['fail', 'SP-PREMIUM'], [$this->rows($room)['premium']['status'], $this->rows($room)['premium']['code']]);
    }

    public function test_no_devices_is_a_failure_pointing_at_the_sleeping_device_guide(): void
    {
        $room = $this->room();
        $this->spotify(['devices' => [], 'state' => null]);

        $rows = $this->rows($room);

        $this->assertSame(['fail', 'SP-ASLEEP'], [$rows['devices']['status'], $rows['devices']['code']]);
        $this->assertSame('warn', $rows['playback']['status']);
    }

    public function test_devices_that_are_all_inactive_get_a_warning_not_a_failure(): void
    {
        $room = $this->room();
        $this->spotify(['devices' => [['id' => 'd1', 'name' => 'iPhone', 'type' => 'Smartphone', 'is_active' => false]], 'state' => null]);

        $this->assertSame('warn', $this->rows($room)['devices']['status']);
    }

    public function test_a_device_chosen_in_room_settings_that_is_no_longer_listed_is_called_out(): void
    {
        $room = $this->room(['active_device_id' => 'gone', 'active_device_name' => "Daan's iPhone"]);
        $this->spotify();

        $row = $this->rows($room)['chosen'];

        $this->assertSame('warn', $row['status']);
        $this->assertStringContainsString("Daan's iPhone", $row['detail']);
    }

    public function test_a_login_spotify_turned_down_stops_the_check_without_calling_spotify(): void
    {
        $room = $this->room(['needs_reconnect_at' => now()]);
        Http::fake();

        $rows = $this->rows($room);

        $this->assertSame(['fail', 'SP-DISCONNECTED'], [$rows['connection']['status'], $rows['connection']['code']]);
        $this->assertSame('warn', $rows['rest']['status']);
        Http::assertNothingSent();
    }

    public function test_spotify_not_answering_the_profile_request_is_a_warning(): void
    {
        $room = $this->room();
        $this->spotify(['profileStatus' => 500]);

        $this->assertSame(['warn', 'SP-CMD'], [$this->rows($room)['connection']['status'], $this->rows($room)['connection']['code']]);
    }

    public function test_a_device_that_ignores_volume_is_mentioned(): void
    {
        $room = $this->room();
        $this->spotify(['state' => [
            'is_playing' => true, 'progress_ms' => 1000, 'shuffle_state' => false, 'repeat_state' => 'off', 'context' => null,
            'device' => ['id' => 'd1', 'supports_volume' => false],
            'item' => ['id' => 't1', 'name' => 'Alpha', 'duration_ms' => 1000, 'artists' => [], 'album' => ['images' => []]],
        ]]);

        $this->assertSame('SP-VOLUME', $this->rows($room)['volume']['code']);
    }

    public function test_a_room_without_spotify_says_so_in_one_row(): void
    {
        $host = User::factory()->create();
        $room = Room::create(['invite_code' => 'DDDDDD-EEEEEE-FFFFFF', 'host_id' => $host->id, 'playback_provider_id' => $host->id])->refresh();

        $rows = app(SpotifyCheck::class)->run($room);

        $this->assertCount(1, $rows);
        $this->assertSame('SP-CONNECT-CREDS', $rows[0]['code']);
    }

    public function test_the_host_runs_it_from_the_hub_and_sees_the_results_with_guide_links(): void
    {
        $room = $this->room();
        $this->spotify(['devices' => [], 'state' => null]);

        $component = Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])
            ->set('activeTab', 'hub')
            ->assertSee('Check Spotify')
            ->call('runSpotifyCheck');

        $html = $component->html();
        $this->assertStringContainsString('Spotify lists no devices', $html);
        $this->assertStringContainsString(route('help').'#sp-asleep', $html);
        $this->assertStringNotContainsString(self::SECRET, $html);
    }

    public function test_a_guest_cannot_run_it_and_never_sees_the_card(): void
    {
        $room = $this->room();
        $this->spotify();
        ['guest_token' => $token] = app(RoomMembership::class)->joinAsGuest($room, 'Mallory');

        Livewire::withCookie(RoomMembership::cookieName($room), $token)->test(ShowRoom::class, ['room' => $room])
            ->call('runSpotifyCheck')
            ->assertSet('spotifyCheck', []);

        Http::assertNothingSent();
    }

    public function test_it_is_rate_limited_so_it_cannot_drain_the_hosts_spotify_quota(): void
    {
        $room = $this->room();
        $this->spotify();
        RateLimiter::clear('spotify-check:'.$room->id);

        $component = Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room]);

        foreach (range(1, 6) as $i) {
            $component->call('runSpotifyCheck');
        }

        $this->assertSame('credentials', $component->get('spotifyCheck')[0]['id'], 'the sixth run still works');
        $component->call('runSpotifyCheck');
        $this->assertSame('limit', $component->get('spotifyCheck')[0]['id']);
    }
}
