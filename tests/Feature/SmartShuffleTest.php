<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\PartyScreen;
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

class SmartShuffleTest extends TestCase
{
    use RefreshDatabase;

    private function room(): Room
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
            'now_playing_started_at' => now()->subSecond(),
        ])->refresh();
        app(RoomMembership::class)->joinAsHost($room);

        return $room;
    }

    private function fakePlayer(bool $shuffle, ?bool $smart): void
    {
        Http::fake([
            'api.spotify.com/v1/me/player/queue' => Http::response(['queue' => []]),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => [['id' => 'd1', 'name' => 'Laptop', 'type' => 'Computer', 'is_active' => true]]]),
            'api.spotify.com/v1/me/player' => Http::response([
                'is_playing' => true, 'progress_ms' => 1000, 'shuffle_state' => $shuffle, 'repeat_state' => 'off',
                ...($smart === null ? [] : ['smart_shuffle' => $smart]),
                'context' => ['uri' => 'spotify:playlist:testplaylist01', 'type' => 'playlist'], 'device' => ['id' => 'd1'],
                'item' => ['id' => 't1', 'name' => 'Alpha Anthem', 'duration_ms' => 180000, 'artists' => [['name' => 'Nobody']], 'album' => ['images' => []]],
            ]),
        ]);
    }

    public function test_sync_records_smart_shuffle_from_the_player_state(): void
    {
        $room = $this->room();
        $this->fakePlayer(shuffle: true, smart: true);

        app(PlaybackSync::class)->sync($room);

        $room->refresh();
        $this->assertTrue($room->shuffle_enabled);
        $this->assertTrue($room->smart_shuffle_enabled);
        $this->assertTrue($room->smartShuffleOn());
    }

    public function test_plain_shuffle_and_a_missing_flag_both_count_as_not_smart(): void
    {
        foreach ([false, null] as $flag) {
            $room = $this->room();
            $this->fakePlayer(shuffle: true, smart: $flag);

            app(PlaybackSync::class)->sync($room);

            $room->refresh();
            $this->assertTrue($room->shuffle_enabled);
            $this->assertFalse($room->smartShuffleOn());

            Room::query()->delete();
        }
    }

    public function test_turning_smart_shuffle_off_in_spotify_is_picked_up(): void
    {
        $room = $this->room();
        $room->update(['shuffle_enabled' => true, 'smart_shuffle_enabled' => true]);
        $this->fakePlayer(shuffle: true, smart: false);

        app(PlaybackSync::class)->sync($room);

        $this->assertFalse($room->refresh()->smart_shuffle_enabled);
    }

    public function test_the_dashboard_and_party_screen_say_smart_shuffle_only_when_it_is_on(): void
    {
        $room = $this->room();
        $this->fakePlayer(shuffle: true, smart: true);
        app(PlaybackSync::class)->sync($room);

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room->refresh()])
            ->assertSee('Smart Shuffle');
        Livewire::test(PartyScreen::class, ['room' => $room])
            ->assertSee('Smart Shuffle is on');

        $room->update(['smart_shuffle_enabled' => false]);

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room->refresh()])
            ->assertDontSee('Smart Shuffle');
        Livewire::test(PartyScreen::class, ['room' => $room])
            ->assertDontSee('Smart Shuffle')
            ->assertSee('Shuffle is on');
    }
}
