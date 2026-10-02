<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\ShowRoom;
use App\Models\Room;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\RoomMembership;
use App\Services\Spotify\PlaybackSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class VolumeSyncTest extends TestCase
{
    use RefreshDatabase;

    private function room(int $volume = 70): Room
    {
        $host = User::factory()->create();
        SpotifyAccount::create([
            'user_id' => $host->id, 'client_id' => 'id', 'client_secret' => 'secret',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
        ]);

        $room = Room::create([
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $host->id, 'playback_provider_id' => $host->id,
            'is_playing' => true, 'now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha Anthem',
            'now_playing_artist' => 'Nobody', 'now_playing_duration_ms' => 180000, 'now_playing_position_ms' => 1000,
            'now_playing_started_at' => now()->subSecond(), 'volume_percent' => $volume,
        ])->refresh();
        app(RoomMembership::class)->joinAsHost($room);

        return $room;
    }

    private function fakePlayer(array $device): void
    {
        Http::fake([
            'api.spotify.com/v1/me/player/queue' => Http::response(['queue' => []]),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => [['id' => 'd1', 'name' => 'Laptop', 'type' => 'Computer', 'is_active' => true]]]),
            'api.spotify.com/v1/me/player/volume*' => Http::response('', 204),
            'api.spotify.com/v1/me/player' => Http::response([
                'is_playing' => true, 'progress_ms' => 1000, 'shuffle_state' => false, 'repeat_state' => 'off',
                'context' => null, 'device' => ['id' => 'd1', ...$device],
                'item' => ['id' => 't1', 'name' => 'Alpha Anthem', 'duration_ms' => 180000, 'artists' => [['name' => 'Nobody']], 'album' => ['images' => []]],
            ]),
        ]);
    }

    public function test_a_volume_changed_on_the_device_is_pulled_into_the_room(): void
    {
        $room = $this->room(volume: 100);
        $this->fakePlayer(['supports_volume' => true, 'volume_percent' => 57]);

        app(PlaybackSync::class)->sync($room);

        $this->assertSame(57, $room->refresh()->volume_percent);
    }

    public function test_the_dashboard_slider_shows_the_real_volume(): void
    {
        $room = $this->room(volume: 100);
        $this->fakePlayer(['supports_volume' => true, 'volume_percent' => 57]);
        app(PlaybackSync::class)->sync($room);

        $html = Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room->refresh()])->html();

        $this->assertStringContainsString('volume: 57', $html);
        $this->assertStringNotContainsString('volume: 100', $html);
    }

    public function test_a_device_that_cannot_be_volume_controlled_leaves_the_room_volume_alone(): void
    {
        $room = $this->room(volume: 70);
        $this->fakePlayer(['supports_volume' => false, 'volume_percent' => 100]);

        app(PlaybackSync::class)->sync($room);

        $this->assertSame(70, $room->refresh()->volume_percent);
    }

    public function test_a_device_that_reports_no_volume_leaves_the_room_volume_alone(): void
    {
        $room = $this->room(volume: 70);
        $this->fakePlayer(['supports_volume' => true]);

        app(PlaybackSync::class)->sync($room);

        $this->assertSame(70, $room->refresh()->volume_percent);
    }

    public function test_setting_the_volume_in_auxroom_is_not_undone_by_a_poll_that_still_reads_the_old_one(): void
    {
        $room = $this->room(volume: 70);
        $this->fakePlayer(['supports_volume' => true, 'volume_percent' => 70]);

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->call('setVolume', 35);

        $room->refresh();
        $this->assertSame(35, $room->volume_percent);
        $this->assertTrue($room->commandedRecently(), 'the sync is held off while Spotify catches up');
    }

    public function test_the_slider_is_disabled_when_the_device_does_not_support_volume(): void
    {
        $room = $this->room(volume: 70);
        $this->fakePlayer(['supports_volume' => false, 'volume_percent' => 100]);
        app(PlaybackSync::class)->sync($room);

        $this->assertFalse($room->refresh()->volume_supported);

        $html = Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->html();

        preg_match_all('/<input type="range"[^>]*wire:change="setVolume[^>]*>/s', $html, $m);
        $this->assertCount(2, $m[0], 'the bar and the mobile menu each have a slider');

        foreach ($m[0] as $input) {
            $this->assertMatchesRegularExpression('/\sdisabled[\s=>]/', $input);
        }
    }

    public function test_the_slider_is_enabled_for_a_device_that_supports_volume_and_comes_back_when_it_does(): void
    {
        $room = $this->room(volume: 70);
        $room->update(['volume_supported' => false]);
        $this->fakePlayer(['supports_volume' => true, 'volume_percent' => 40]);
        app(PlaybackSync::class)->sync($room);

        $this->assertTrue($room->refresh()->volume_supported);

        $html = Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->html();
        preg_match_all('/<input type="range"[^>]*wire:change="setVolume[^>]*>/s', $html, $m);

        foreach ($m[0] as $input) {
            $this->assertDoesNotMatchRegularExpression('/\sdisabled[\s=>]/', $input);
        }
    }

    public function test_setting_the_volume_on_an_unsupported_device_is_refused_with_a_reason(): void
    {
        $room = $this->room(volume: 70);
        $room->update(['volume_supported' => false]);
        $this->fakePlayer(['supports_volume' => false]);

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room->refresh()])
            ->call('setVolume', 20)
            ->assertNotSet('controlError', '');

        $this->assertSame(70, $room->refresh()->volume_percent);
    }
}
