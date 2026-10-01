<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\Spotify\PlaybackSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaybackSyncTest extends TestCase
{
    use RefreshDatabase;

    private function playingRoom(): Room
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

        return Room::create([
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC',
            'host_id' => $host->id,
            'playback_provider_id' => $host->id,
            'is_playing' => true,
            'now_playing_track_id' => 'track1',
            'now_playing_name' => 'Song',
            'now_playing_artist' => 'Artist',
            'now_playing_duration_ms' => 200000,
            'now_playing_position_ms' => 42000,
        ])->refresh();
    }

    public function test_no_visible_device_clears_the_now_playing_state(): void
    {
        Http::fake([
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => []]),
            'api.spotify.com/v1/me/player' => Http::response('', 204),
        ]);
        $room = $this->playingRoom();

        app(PlaybackSync::class)->sync($room);

        $room->refresh();
        $this->assertFalse($room->is_playing);
        $this->assertNull($room->now_playing_track_id);
        $this->assertNull($room->nowPlayingDetails());
    }

    public function test_an_idle_but_still_listed_device_keeps_the_track_and_its_resume_position(): void
    {
        Http::fake([
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => [
                ['id' => 'd1', 'name' => 'Phone', 'type' => 'Smartphone', 'is_active' => false],
            ]]),
            'api.spotify.com/v1/me/player' => Http::response('', 204),
        ]);
        $room = $this->playingRoom();

        app(PlaybackSync::class)->sync($room);

        $room->refresh();
        $this->assertFalse($room->is_playing, 'it is no longer playing');
        $this->assertSame('track1', $room->now_playing_track_id, 'but Play must still be able to resume it');
        $this->assertSame(42000, $room->now_playing_position_ms);
    }

    public function test_a_failed_request_never_changes_what_the_room_thinks_is_playing(): void
    {
        foreach ([429, 500, 401] as $status) {
            Http::fake(['api.spotify.com/v1/me/player' => Http::response(['error' => 'x'], $status)]);
            $room = $this->playingRoom();

            app(PlaybackSync::class)->sync($room);

            $room->refresh();
            $this->assertTrue($room->is_playing, "status $status must not flip is_playing");
            $this->assertSame('track1', $room->now_playing_track_id, "status $status must not clear the track");

            $room->delete();
            User::query()->delete();
        }
    }
}
