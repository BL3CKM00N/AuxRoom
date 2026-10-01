<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\PartyScreen;
use App\Livewire\Dashboard\ShowRoom;
use App\Models\Room;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\RoomMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class InactivePlaybackDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function inactiveRoom(): Room
    {
        $host = User::factory()->create();
        SpotifyAccount::create([
            'user_id' => $host->id,
            'client_id' => 'id',
            'client_secret' => 'secret',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
        ]);

        $room = Room::create([
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC',
            'host_id' => $host->id,
            'playback_provider_id' => $host->id,
            'now_playing_track_id' => 'track1',
            'now_playing_name' => 'Zyxwv Dead Track',
            'now_playing_artist' => 'Gone Artist',
            'now_playing_duration_ms' => 200000,
            'now_playing_position_ms' => 42000,
            'is_playing' => false,
            'playback_inactive_at' => now(),
        ])->refresh();

        app(RoomMembership::class)->joinAsHost($room);

        // Spotify still LISTS a device (it lingers after really going away),
        // which is exactly what used to keep the stale track on screen.
        Http::fake([
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => [
                ['id' => 'd1', 'name' => 'Phone', 'type' => 'Smartphone', 'is_active' => false],
            ]]),
            'api.spotify.com/v1/me/player/queue' => Http::response(['queue' => []]),
            'api.spotify.com/*' => Http::response('', 204),
        ]);

        return $room;
    }

    public function test_the_dashboard_says_no_device_instead_of_showing_a_dead_paused_track(): void
    {
        $room = $this->inactiveRoom();

        Livewire::actingAs($room->host)
            ->test(ShowRoom::class, ['room' => $room])
            ->assertSee('No device found')
            ->assertSee("Nothing's playing anywhere")
            ->assertDontSee('Zyxwv Dead Track')
            ->assertDontSee('Gone Artist')
            ->assertDontSee('On pause');
    }

    public function test_the_party_screen_does_the_same(): void
    {
        $room = $this->inactiveRoom();

        Livewire::test(PartyScreen::class, ['room' => $room])
            ->assertSee('No device found')
            ->assertSee('Ask the host to open Spotify on a device')
            ->assertDontSee('Zyxwv Dead Track')
            ->assertDontSee('PAUSED');
    }

    public function test_play_still_resumes_the_exact_track_and_position_once_a_device_is_back(): void
    {
        $room = $this->inactiveRoom();

        Livewire::actingAs($room->host)
            ->test(ShowRoom::class, ['room' => $room])
            ->call('play');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/me/player/play')
            && $r['uris'] === ['spotify:track:track1']
            && $r['position_ms'] === 42000);

        $room->refresh();
        $this->assertTrue($room->is_playing);
        $this->assertFalse($room->playbackInactive(), 'resuming ends the inactive state');
        $this->assertSame('Zyxwv Dead Track', $room->nowPlayingDetails()->name);
    }

    public function test_both_play_buttons_stay_enabled_so_the_retained_track_can_be_resumed(): void
    {
        $room = $this->inactiveRoom();

        $html = Livewire::actingAs($room->host)
            ->test(ShowRoom::class, ['room' => $room])
            ->html();

        preg_match_all('/<button[^>]*wire:click="play"[^>]*>/', $html, $matches);

        $this->assertGreaterThanOrEqual(2, count($matches[0]), 'the card and the player bar each have one');

        foreach ($matches[0] as $button) {
            // The attribute, not the `disabled:` Tailwind variants in the class list.
            $this->assertDoesNotMatchRegularExpression('/\sdisabled[\s=>]/', $button);
        }
    }
}
