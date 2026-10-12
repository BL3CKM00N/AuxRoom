<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\ShowRoom;
use App\Models\Room;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\RoomMembership;
use App\Services\Spotify\WatchState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** After a skip the next track shows at once; the watcher confirms it a moment later. */
class SkipPredictionTest extends TestCase
{
    use RefreshDatabase;

    private const TRACKS = ['t1' => 'Alpha Anthem', 't2' => 'Bravo Ballad', 't3' => 'Charlie Chorus'];

    private function track(string $id): array
    {
        return ['id' => $id, 'uri' => "spotify:track:{$id}", 'name' => self::TRACKS[$id], 'type' => 'track', 'duration_ms' => 180000, 'artists' => [['name' => 'Nobody']], 'album' => ['images' => []]];
    }

    private function room(array $attributes = [], array $queue = ['t2', 't3']): Room
    {
        $host = User::factory()->create();
        SpotifyAccount::create([
            'user_id' => $host->id, 'client_id' => 'id', 'client_secret' => 'secret',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
        ]);
        $room = Room::create($attributes + [
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $host->id, 'playback_provider_id' => $host->id,
            'is_playing' => true, 'repeat_mode' => 'off', 'now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha Anthem',
            'now_playing_artist' => 'Nobody', 'now_playing_duration_ms' => 180000, 'now_playing_position_ms' => 90000,
            'now_playing_started_at' => now()->subSeconds(90), 'now_playing_context_uri' => 'spotify:playlist:testplaylist01',
        ])->refresh();
        app(RoomMembership::class)->joinAsHost($room);

        Cache::flush();
        Http::fake([
            'api.spotify.com/v1/me/player/queue*' => Http::response(['queue' => array_map(fn ($id) => $this->track($id), $queue)]),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => [['id' => 'd1', 'name' => 'Laptop', 'type' => 'Computer', 'is_active' => true, 'supports_volume' => true]]]),
            'api.spotify.com/v1/playlists/*' => Http::response(['items' => array_map(fn ($id) => ['item' => $this->track($id)], array_keys(self::TRACKS)), 'next' => null]),
            'api.spotify.com/v1/me/player/next*' => Http::response('', 204),
            'api.spotify.com/v1/me/player/previous*' => Http::response('', 204),
            'api.spotify.com/v1/me/player' => Http::response('', 204),
        ]);
        WatchState::putQueue($room, array_map(fn ($id) => $this->track($id) + ['uri' => "spotify:track:{$id}", 'artist' => 'Nobody', 'album_art_url' => null, 'duration_ms' => 180000], $queue));

        return $room;
    }

    private function playerReads(): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'GET' && parse_url($r->url(), PHP_URL_PATH) === '/v1/me/player')->count();
    }

    private function skipped(): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/me/player/next'))->count();
    }

    public function test_skipping_shows_the_next_track_immediately_and_asks_the_watcher_to_confirm(): void
    {
        $room = $this->room();
        WatchState::beat();

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->call('skip');

        $room->refresh();
        $this->assertSame(1, $this->skipped(), 'Spotify was told to skip');
        $this->assertSame('t2', $room->now_playing_track_id, 'the next track is shown at once');
        $this->assertSame('Bravo Ballad', $room->now_playing_name);
        $this->assertSame(0, $room->now_playing_position_ms);
        $this->assertTrue($room->is_playing);
        $this->assertSame(['skip', 't1'], [WatchState::burst($room)['kind'], WatchState::burst($room)['from_track']], 'and the watcher checks until Spotify agrees');
        $this->assertSame(0, $this->playerReads(), 'no read of the old state that could undo it');
    }

    public function test_a_guest_queued_song_that_is_next_is_used_up_by_the_skip(): void
    {
        $room = $this->room();
        $member = $room->members()->first();
        $item = $room->queueItems()->create(['added_by_id' => $member->id, 'spotify_track_id' => 't2', 'name' => 'Bravo Ballad', 'artist' => 'Nobody', 'duration_ms' => 180000, 'position' => 1]);
        WatchState::beat();

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->call('skip');

        $this->assertNotNull($item->refresh()->played_at);
        $this->assertSame($item->id, $room->refresh()->now_playing_queue_item_id);
    }

    public function test_with_shuffle_on_nothing_is_guessed(): void
    {
        $room = $this->room(['shuffle_enabled' => true]);
        WatchState::beat();

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->call('skip');

        $this->assertSame('t1', $room->refresh()->now_playing_track_id, 'shuffle picks something we cannot know; the watcher shows it');
        $this->assertSame(1, $this->skipped());
        $this->assertNotNull(WatchState::burst($room), 'but it is confirmed fast');
    }

    public function test_repeat_one_and_the_end_of_a_playlist_are_not_guessed_either(): void
    {
        $repeatOne = $this->room(['repeat_mode' => 'track']);
        WatchState::beat();
        Livewire::actingAs($repeatOne->host)->test(ShowRoom::class, ['room' => $repeatOne])->call('skip');
        $this->assertSame('t1', $repeatOne->refresh()->now_playing_track_id);

        // On the last track of the playlist with repeat off, Spotify pads its queue with the start;
        // there is nothing real to show next.
        $last = $this->room(['invite_code' => 'ZZZZZZ-YYYYYY-XXXXXX', 'now_playing_track_id' => 't3', 'now_playing_name' => 'Charlie Chorus'], queue: ['t1', 't2', 't3', 't1', 't2', 't3']);
        WatchState::beat();
        Livewire::actingAs($last->host)->test(ShowRoom::class, ['room' => $last])->call('skip');
        $this->assertSame('t3', $last->refresh()->now_playing_track_id);
    }

    public function test_without_the_watcher_a_skip_asks_spotify_right_away_as_before(): void
    {
        $room = $this->room();

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->call('skip');

        $this->assertSame(1, $this->skipped());
        $this->assertGreaterThanOrEqual(1, $this->playerReads(), 'the fallback path');
        $this->assertSame('t1', $room->refresh()->now_playing_track_id, 'no prediction without someone to confirm it');
    }

    public function test_previous_cannot_be_predicted_but_is_confirmed_fast(): void
    {
        $room = $this->room();
        WatchState::beat();

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->call('previous');

        $this->assertSame('previous', WatchState::burst($room)['kind']);
        $this->assertSame(0, $this->playerReads());
        $this->assertSame('t1', $room->refresh()->now_playing_track_id);
    }

    public function test_pause_waits_for_spotify_to_stop_before_it_is_trusted(): void
    {
        $room = $this->room();
        Http::fake(['api.spotify.com/v1/me/player/pause*' => Http::response('', 204), 'api.spotify.com/*' => Http::response('', 204)]);
        WatchState::beat();

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->call('pause');

        $this->assertFalse($room->refresh()->is_playing);
        $this->assertSame('pause', WatchState::burst($room)['kind']);
    }
}
