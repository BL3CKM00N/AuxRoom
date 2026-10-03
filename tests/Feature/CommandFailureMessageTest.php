<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\ShowRoom;
use App\Models\Room;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\RoomMembership;
use App\Services\Spotify\CommandFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CommandFailureMessageTest extends TestCase
{
    use RefreshDatabase;

    private const ASLEEP = 'asleep or closed';

    private function room(bool $playing): Room
    {
        $host = User::factory()->create();
        SpotifyAccount::create([
            'user_id' => $host->id, 'client_id' => 'id', 'client_secret' => 'secret',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
            'active_device_id' => 'phone1', 'active_device_name' => "Daan's iPhone",
        ]);

        $room = Room::create([
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $host->id, 'playback_provider_id' => $host->id,
            'is_playing' => $playing, 'now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha Anthem',
            'now_playing_artist' => 'Nobody', 'now_playing_duration_ms' => 180000, 'now_playing_position_ms' => 5000,
            'now_playing_context_uri' => 'spotify:playlist:testplaylist01', 'now_playing_started_at' => $playing ? now() : null,
        ])->refresh();
        app(RoomMembership::class)->joinAsHost($room);

        return $room;
    }

    /** Every Spotify call answers with this status and body, except reads that the page needs to render. */
    private function commandsFailWith(int $status, array|string $body): void
    {
        Http::fake([
            'api.spotify.com/v1/me/player/queue' => Http::response(['queue' => []]),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => []]),
            'api.spotify.com/v1/me/player/*' => Http::response($body, $status),
            'api.spotify.com/v1/me/player' => Http::response('', 204),
        ]);
    }

    public function test_a_play_aimed_at_a_sleeping_phone_says_so_instead_of_blaming_the_host_hub(): void
    {
        $room = $this->room(playing: false);
        // What prod logged: 502 "Bad gateway" for a command aimed at an iPhone that had gone to sleep.
        $this->commandsFailWith(502, ['error' => ['status' => 502, 'message' => 'Bad gateway.']]);

        $component = Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->call('play');

        $error = $component->get('controlError');
        $this->assertStringContainsString(self::ASLEEP, $error);
        $this->assertStringNotContainsString('Host Hub', $error);
    }

    public function test_no_active_device_from_spotify_gets_the_same_message(): void
    {
        $room = $this->room(playing: true);
        $this->commandsFailWith(404, ['error' => ['status' => 404, 'message' => 'Player command failed: No active device found', 'reason' => 'NO_ACTIVE_DEVICE']]);

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->call('pause')
            ->assertSet('controlError', 'Spotify on your playback device is asleep or closed. Open the Spotify app on it, then try again.');
    }

    public function test_a_free_account_is_told_premium_is_required(): void
    {
        $room = $this->room(playing: true);
        $this->commandsFailWith(403, ['error' => ['status' => 403, 'message' => 'Player command failed: Premium required', 'reason' => 'PREMIUM_REQUIRED']]);

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->call('pause')
            ->assertSet('controlError', 'Spotify only lets Premium accounts control playback.');
    }

    public function test_any_other_failure_keeps_the_generic_message(): void
    {
        $room = $this->room(playing: true);
        $this->commandsFailWith(500, ['error' => ['status' => 500, 'message' => 'Server error']]);

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->call('pause')
            ->assertSet('controlError', "Spotify couldn't pause playback.");
    }

    public function test_an_earlier_failure_does_not_colour_the_message_for_a_later_one(): void
    {
        $room = $this->room(playing: true);

        $this->commandsFailWith(502, ['error' => ['status' => 502]]);
        $component = Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->call('pause');
        $this->assertStringContainsString(self::ASLEEP, $component->get('controlError'));

        Http::swap(new \Illuminate\Http\Client\Factory);
        $this->commandsFailWith(500, ['error' => ['status' => 500]]);
        $component->call('pause')->assertSet('controlError', "Spotify couldn't pause playback.");
    }

    public function test_the_failure_reader_understands_what_spotify_sends(): void
    {
        $failure = new CommandFailure;

        foreach ([[502, ''], [503, ''], [504, ''], [404, ''], [400, '{"error":{"reason":"NO_ACTIVE_DEVICE"}}']] as [$status, $body]) {
            $failure->record($status, $body);
            $this->assertTrue($failure->deviceAsleep(), "{$status} {$body}");
        }

        $failure->record(403, '{"error":{"reason":"PREMIUM_REQUIRED"}}');
        $this->assertFalse($failure->deviceAsleep());
        $this->assertTrue($failure->premiumRequired());

        $failure->record(500, 'not json');
        $this->assertSame('generic', $failure->message('generic'));

        $failure->record(502, null);
        $failure->clear();
        $this->assertSame('generic', $failure->message('generic'), 'cleared means nothing to explain');
    }
}
