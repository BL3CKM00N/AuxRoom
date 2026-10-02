<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\SpotifyAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InspectSpotifyCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_TOKEN = 'super-secret-access-token-xyz';

    private const SECRET_CLIENT = 'super-secret-client-secret-abc';

    private function room(?string $storedPlaylist = null): Room
    {
        $host = User::factory()->create();
        SpotifyAccount::create([
            'user_id' => $host->id,
            'client_id' => 'client-id',
            'client_secret' => self::SECRET_CLIENT,
            'access_token' => self::SECRET_TOKEN,
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);

        return Room::create([
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC',
            'host_id' => $host->id,
            'playback_provider_id' => $host->id,
            'fallback_playlist_uri' => $storedPlaylist,
        ]);
    }

    private function track(string $id, string $name): array
    {
        return [
            'id' => $id, 'name' => $name, 'uri' => "spotify:track:{$id}", 'duration_ms' => 180000,
            'artists' => [['name' => 'Artist']], 'album' => ['images' => []],
            'available_markets' => ['NL', 'US'], 'preview_url' => 'https://example.com/p',
        ];
    }

    /** The shape Spotify really returns: playing the LAST of a 3-track playlist, repeat off, queue padded by wrapping. */
    private function fakeLastTrackOfThree(bool $contentsReadable = true, string $repeat = 'off'): void
    {
        $tracks = [$this->track('t1', 'One'), $this->track('t2', 'Two'), $this->track('t3', 'Three')];

        Http::fake([
            'api.spotify.com/v1/me/player/queue' => Http::response([
                'currently_playing' => $tracks[2],
                'queue' => [$tracks[0], $tracks[1], $tracks[2], $tracks[0], $tracks[1], $tracks[2]],
            ]),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => [
                ['id' => 'real-device-id', 'name' => "Daan's iPhone", 'type' => 'Smartphone', 'is_active' => true],
            ]]),
            'api.spotify.com/v1/me/player' => Http::response([
                'is_playing' => true,
                'progress_ms' => 1000,
                'shuffle_state' => false,
                'repeat_state' => $repeat,
                'item' => $tracks[2],
                'context' => ['type' => 'playlist', 'uri' => 'spotify:playlist:PL1'],
                'actions' => ['disallows' => ['skipping_next' => true]],
                'device' => ['id' => 'real-device-id', 'name' => "Daan's iPhone", 'type' => 'Smartphone', 'is_active' => true],
            ]),
            'api.spotify.com/v1/playlists/PL1/items*' => $contentsReadable
                ? Http::response(['total' => 3, 'next' => null, 'items' => array_map(fn ($t) => ['item' => $t], $tracks)])
                : Http::response(['id' => 'PL1', 'name' => 'Someone else\'s playlist'], 200),
            'api.spotify.com/v1/playlists/PL1/tracks*' => Http::response(['error' => ['status' => 403]], 403),
        ]);
    }

    public function test_it_reports_the_wrap_around_padding_when_repeat_is_off(): void
    {
        $this->room();
        $this->fakeLastTrackOfThree();

        $this->artisan('spotify:inspect')
            ->expectsOutputToContain('THE CURRENT TRACK AGAIN')
            ->expectsOutputToContain('Current track is number')
            ->expectsOutputToContain('Tracks genuinely left after it')
            ->expectsOutputToContain('beyond the real end of the list')
            ->assertExitCode(0);
    }

    public function test_a_wrapped_queue_is_not_flagged_when_repeat_is_on(): void
    {
        $this->room();
        $this->fakeLastTrackOfThree(repeat: 'context');

        $this->artisan('spotify:inspect')
            ->expectsOutputToContain("Repeat is 'context', so a wrapped queue is real here.")
            ->assertExitCode(0);
    }

    public function test_it_says_why_clicking_a_coming_up_track_does_nothing(): void
    {
        $this->room(storedPlaylist: null);
        $this->fakeLastTrackOfThree();

        $this->artisan('spotify:inspect')
            ->expectsOutputToContain('ROOM HAS NO PLAYLIST STORED')
            ->assertExitCode(0);
    }

    public function test_it_reports_when_spotify_will_not_reveal_a_playlists_contents(): void
    {
        $this->room();
        $this->fakeLastTrackOfThree(contentsReadable: false);

        $this->artisan('spotify:inspect')
            ->expectsOutputToContain('NO items in the response')
            ->expectsOutputToContain('Development Mode')
            ->assertExitCode(0);
    }

    public function test_it_never_prints_credentials_and_only_ever_sends_get_requests(): void
    {
        $this->room();
        $this->fakeLastTrackOfThree();

        Artisan::call('spotify:inspect', ['--save' => storage_path('framework/testing/inspect-out')]);
        $output = Artisan::output();

        $this->assertStringContainsString('Playback state', $output, 'sanity: the command really ran and printed');
        foreach ([self::SECRET_TOKEN, self::SECRET_CLIENT, 'refresh-token'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }

        $this->assertNotEmpty(Http::recorded());
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method(), 'read-only: '.$request->url());
            $this->assertStringNotContainsString(self::SECRET_TOKEN, $request->url());
        }

        File::deleteDirectory(storage_path('framework/testing/inspect-out'));
    }

    public function test_saved_fixtures_have_hardware_and_bloat_stripped(): void
    {
        $this->room();
        $this->fakeLastTrackOfThree();
        $directory = storage_path('framework/testing/inspect-fixtures');
        File::deleteDirectory($directory);

        $this->artisan('spotify:inspect', ['--save' => $directory])->assertExitCode(0);

        foreach (['player', 'queue', 'devices', 'context'] as $name) {
            $this->assertFileExists("{$directory}/{$name}.json");
        }

        $all = collect(File::files($directory))->map(fn ($f) => File::get($f->getPathname()))->implode("\n");

        $this->assertStringNotContainsString("Daan's iPhone", $all, 'real device name must be replaced');
        $this->assertStringNotContainsString('real-device-id', $all, 'real device id must be replaced');
        $this->assertStringNotContainsString('available_markets', $all);
        $this->assertStringNotContainsString(self::SECRET_TOKEN, $all);
        $this->assertStringContainsString('Test device', $all);

        File::deleteDirectory($directory);
    }

    public function test_it_explains_when_nothing_is_active(): void
    {
        $this->room();
        Http::fake([
            'api.spotify.com/v1/me/player/queue' => Http::response(['currently_playing' => null, 'queue' => []]),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => []]),
            'api.spotify.com/v1/me/player' => Http::response('', 204),
        ]);

        $this->artisan('spotify:inspect')
            ->expectsOutputToContain('Nothing active anywhere (HTTP 204)')
            ->assertExitCode(0);
    }

    public function test_it_refuses_clearly_without_a_room_or_with_a_flagged_account(): void
    {
        $this->artisan('spotify:inspect')->expectsOutputToContain('There are no open rooms.')->assertExitCode(1);

        $room = $this->room();
        $room->playbackProvider->spotifyAccount->update(['needs_reconnect_at' => now()]);
        Http::fake();

        $this->artisan('spotify:inspect')->expectsOutputToContain('needs_reconnect_at is set')->assertExitCode(1);
        Http::assertNothingSent();
    }
}
