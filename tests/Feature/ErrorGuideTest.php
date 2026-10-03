<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\ShowRoom;
use App\Models\Room;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\RoomMembership;
use App\Support\Errors\ErrorCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ErrorGuideTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'ZZ-very-secret-token-123';

    public function test_every_entry_explains_what_why_and_what_to_do_for_both_roles(): void
    {
        foreach (ErrorCatalog::all() as $code => $entry) {
            $this->assertSame($code, $entry['code']);
            $this->assertArrayHasKey($entry['group'], ErrorCatalog::GROUPS, "{$code} has a known group");

            foreach (['title', 'what', 'why'] as $field) {
                $this->assertNotSame('', trim($entry[$field]), "{$code} has a {$field}");
            }

            $this->assertNotEmpty($entry['host'], "{$code} tells the host what to do");
            $this->assertNotEmpty($entry['guest'], "{$code} tells a guest what to do");
        }
    }

    public function test_every_code_the_app_can_raise_has_a_catalog_entry(): void
    {
        $used = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getFilename() === 'ErrorCatalog.php') {
                continue;
            }

            preg_match_all("/['\"]((?:".implode('|', array_keys(ErrorCatalog::GROUPS)).")-[A-Z0-9-]+)['\"]/", $file->getContents(), $m);
            $used = array_merge($used, $m[1]);
        }

        $this->assertNotEmpty($used);

        foreach (array_unique($used) as $code) {
            $this->assertNotNull(ErrorCatalog::find($code), "{$code} is raised in the app but missing from the catalog");
        }
    }

    public function test_the_help_page_is_public_and_lists_every_error_with_an_anchor(): void
    {
        $html = $this->get('/help')->assertOk()->assertSee('Help with errors')->getContent();

        foreach (ErrorCatalog::all() as $code => $entry) {
            $this->assertStringContainsString('id="'.strtolower($code).'"', $html);
            $this->assertStringContainsString(e($entry['title']), $html);
        }

        $this->assertStringContainsString('type="search"', $html);
        $this->assertStringContainsString('livewire.js', $html, 'Alpine comes with Livewire\'s script; without it the expanders and search are dead');
    }

    public function test_the_notice_shows_steps_for_the_role_and_a_code_but_no_secrets(): void
    {
        $host = Blade::render('<x-error-notice message="Boom" code="ROOM-NO-PERMISSION" :is-host="true" />');
        $guest = Blade::render('<x-error-notice message="Boom" code="ROOM-NO-PERMISSION" :is-host="false" />');

        $this->assertStringContainsString('[ROOM-NO-PERMISSION]', $host);
        $this->assertStringContainsString('Guests tab', $host);
        $this->assertStringNotContainsString('Guests tab', $guest);
        $this->assertStringContainsString('Ask the host to allow it for you', $guest);
        $this->assertStringContainsString('Copy details', $guest);
        $this->assertStringContainsString(route('help').'#room-no-permission', $guest);
    }

    public function test_an_unknown_code_still_shows_the_message_without_a_broken_link(): void
    {
        $html = Blade::render('<x-error-notice message="Plain message" code="NOPE-1" :is-host="true" />');

        $this->assertStringContainsString('Plain message', $html);
        $this->assertStringNotContainsString('More info', $html);
    }

    private function hostRoom(): Room
    {
        $host = User::factory()->create();
        SpotifyAccount::create([
            'user_id' => $host->id, 'client_id' => 'id', 'client_secret' => self::SECRET,
            'access_token' => self::SECRET, 'refresh_token' => self::SECRET, 'token_expires_at' => now()->addHour(),
        ]);
        $room = Room::create([
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $host->id, 'playback_provider_id' => $host->id,
            'is_playing' => true, 'now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha', 'now_playing_artist' => 'Nobody',
            'now_playing_duration_ms' => 180000, 'now_playing_started_at' => now(),
        ])->refresh();
        app(RoomMembership::class)->joinAsHost($room);

        return $room;
    }

    public function test_a_failed_pause_on_a_sleeping_phone_shows_the_code_the_steps_and_what_spotify_said(): void
    {
        $room = $this->hostRoom();
        Http::fake([
            'api.spotify.com/v1/me/player/queue' => Http::response(['queue' => []]),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => []]),
            'api.spotify.com/v1/me/player/*' => Http::response(['error' => ['status' => 502, 'message' => 'Bad gateway.']], 502),
            'api.spotify.com/v1/me/player' => Http::response('', 204),
        ]);

        $component = Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->call('pause');
        $html = $component->html();

        $component->assertSet('controlErrorCode', 'SP-ASLEEP');
        $this->assertStringContainsString('[SP-ASLEEP]', $html);
        $this->assertStringContainsString('Open the Spotify app on the playback device', $html, 'the host steps');
        $this->assertStringContainsString('Spotify answered 502: Bad gateway.', $html, 'what Spotify said, ready to copy');
        $this->assertStringNotContainsString(self::SECRET, $html, 'no token or secret anywhere on the page');
    }

    public function test_a_cleared_error_takes_its_code_with_it(): void
    {
        $room = $this->hostRoom();
        Http::fake(['api.spotify.com/*' => Http::response('', 204)]);

        $component = Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])
            ->call('playFromPlaylist', 'missing')
            ->assertSet('controlErrorCode', 'SP-NO-CONTEXT');

        $component->call('search')->assertSet('controlError', '')->assertSet('controlErrorCode', '');
    }

    public function test_a_cancelled_spotify_connection_is_now_visible_on_the_dashboard(): void
    {
        $room = $this->hostRoom();
        Http::fake(['api.spotify.com/*' => Http::response('', 204)]);

        $this->actingAs($room->host)
            ->withSession(['spotify_oauth_state' => 'abc'])
            ->get('/spotify/callback?error=access_denied&state=abc')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error_code', 'SP-CONNECT-CANCELLED');

        $this->actingAs($room->host)
            ->withSession(['error' => 'Spotify connection was cancelled: access_denied', 'error_code' => 'SP-CONNECT-CANCELLED'])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Spotify connection was cancelled')
            ->assertSee('[SP-CONNECT-CANCELLED]', false);
    }

    public function test_a_rejected_connection_reports_spotifys_reason_without_the_credentials(): void
    {
        $host = User::factory()->create();
        SpotifyAccount::create(['user_id' => $host->id, 'client_id' => 'id', 'client_secret' => self::SECRET]);
        Http::fake(['accounts.spotify.com/*' => Http::response(['error' => 'invalid_client', 'error_description' => 'Invalid client secret'], 400)]);

        $response = $this->actingAs($host)
            ->withSession(['spotify_oauth_state' => 'abc'])
            ->get('/spotify/callback?code=thecode&state=abc');

        $response->assertRedirect(route('dashboard'))
            ->assertSessionHas('error_code', 'SP-CONNECT-REJECTED')
            ->assertSessionHas('error_details', 'Spotify answered 400 (invalid_client)');

        $this->assertStringNotContainsString(self::SECRET, json_encode(session()->all()));
    }
}
