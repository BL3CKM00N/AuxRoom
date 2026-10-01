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

    /** @return array<string, array{0: array}> */
    public static function nothingActiveScenarios(): array
    {
        return [
            'no device is listed at all' => [[]],
            'Spotify still lists the device that went away' => [[['id' => 'd1', 'name' => 'Phone', 'type' => 'Smartphone', 'is_active' => false]]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nothingActiveScenarios')]
    public function test_nothing_active_hides_the_track_but_keeps_it_for_resuming(array $listedDevices): void
    {
        Http::fake([
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => $listedDevices]),
            'api.spotify.com/v1/me/player' => Http::response('', 204),
        ]);
        $room = $this->playingRoom();

        app(PlaybackSync::class)->sync($room);

        $room->refresh();
        $this->assertFalse($room->is_playing);
        $this->assertTrue($room->playbackInactive());
        $this->assertNull($room->nowPlayingDetails(), 'a dead track must not be shown as paused');
        $this->assertSame('track1', $room->now_playing_track_id, 'but Play must still be able to resume it');
        $this->assertSame(42000, $room->now_playing_position_ms);
    }

    public function test_an_inactive_room_recovers_the_moment_spotify_reports_playback_again(): void
    {
        Http::fake(['api.spotify.com/v1/me/player' => Http::response([
            'is_playing' => false,
            'progress_ms' => 42000,
            'item' => ['id' => 'track1', 'name' => 'Song', 'artists' => [['name' => 'Artist']], 'album' => ['images' => []], 'duration_ms' => 200000],
            'device' => ['id' => 'd1'],
            'shuffle_state' => false,
            'repeat_state' => 'off',
        ])]);
        $room = $this->playingRoom();
        $room->update(['is_playing' => false, 'playback_inactive_at' => now()]);

        app(PlaybackSync::class)->sync($room);

        $room->refresh();
        $this->assertFalse($room->playbackInactive(), 'even an unchanged paused state must clear the flag');
        $this->assertSame('Song', $room->nowPlayingDetails()->name);
    }

    public function test_anything_that_starts_playing_ends_the_inactive_state(): void
    {
        $room = $this->playingRoom();
        $room->update(['is_playing' => false, 'playback_inactive_at' => now()]);

        $room->update(['is_playing' => true]);

        $this->assertNull($room->fresh()->playback_inactive_at);
    }

    public function test_the_fallback_playlist_is_not_presented_as_playing_once_inactive(): void
    {
        $room = $this->playingRoom();
        $room->update(['is_playing_fallback' => true, 'is_playing' => false, 'playback_inactive_at' => now()]);

        $this->assertTrue($room->is_playing_fallback, 'kept, because resuming a playlist track relies on it');
        $this->assertFalse($room->fallbackIsShown());
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
