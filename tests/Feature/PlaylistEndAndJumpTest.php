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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A three-track playlist of invented songs (this repo is public). The shapes
 * match what Spotify really sends, including what it does at the end of a
 * playlist with repeat off: padding the queue by wrapping, then parking on
 * the first track, paused at 0:00.
 */
class PlaylistEndAndJumpTest extends TestCase
{
    use RefreshDatabase;

    private const CONTEXT = 'spotify:playlist:testplaylist01';

    private const TRACKS = ['t1' => 'Alpha Anthem', 't2' => 'Bravo Ballad', 't3' => 'Charlie Chorus'];

    private function room(array $attributes = []): Room
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

        $room = Room::create($attributes + [
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC',
            'host_id' => $host->id,
            'playback_provider_id' => $host->id,
            'is_playing' => true,
            'repeat_mode' => 'off',
            'now_playing_track_id' => 't3',
            'now_playing_context_uri' => self::CONTEXT,
            'now_playing_name' => 'Charlie Chorus',
            'now_playing_artist' => 'Nobody',
            'now_playing_duration_ms' => 180000,
            'now_playing_position_ms' => 170000,
            'now_playing_started_at' => now()->subSeconds(170),
        ])->refresh();

        app(RoomMembership::class)->joinAsHost($room);

        return $room;
    }

    private function trackJson(string $id): array
    {
        return [
            'id' => $id, 'uri' => "spotify:track:{$id}", 'name' => self::TRACKS[$id], 'type' => 'track',
            'duration_ms' => 180000, 'artists' => [['name' => 'Nobody']], 'album' => ['images' => []],
        ];
    }

    /** @param array<int, string> $queueIds */
    private function fakeSpotify(?array $state, array $queueIds, bool $orderReadable = true): void
    {
        Http::fake([
            'api.spotify.com/v1/me/player/queue' => Http::response(['queue' => array_map(fn ($id) => $this->trackJson($id), $queueIds)]),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => [['id' => 'd1', 'name' => 'Laptop', 'type' => 'Computer', 'is_active' => true]]]),
            'api.spotify.com/v1/playlists/testplaylist01/*' => $orderReadable
                ? Http::response(['items' => array_map(fn ($id) => ['item' => $this->trackJson($id)], array_keys(self::TRACKS)), 'next' => null])
                : Http::response(['error' => ['status' => 403]], 403),
            'api.spotify.com/v1/me/player/play*' => Http::response('', 204),
            'api.spotify.com/v1/me/player/shuffle*' => Http::response('', 204),
            'api.spotify.com/v1/me/player' => $state
                ? Http::response([
                    'is_playing' => $state['is_playing'],
                    'progress_ms' => $state['progress_ms'],
                    'shuffle_state' => false,
                    'repeat_state' => $state['repeat'] ?? 'off',
                    'context' => ['uri' => self::CONTEXT, 'type' => 'playlist'],
                    'device' => ['id' => 'd1'],
                    'item' => $this->trackJson($state['track']),
                ])
                : Http::response('', 204),
        ]);
    }

    /** What Spotify reports once the last song has ended: first track, paused, 0:00. */
    private function parkedOnFirstTrack(): void
    {
        $this->fakeSpotify(['track' => 't1', 'is_playing' => false, 'progress_ms' => 0], ['t2', 't3', 't1', 't2', 't3', 't1', 't2', 't3']);
    }

    public function test_sync_remembers_which_playlist_spotify_is_playing_from(): void
    {
        $room = $this->room(['now_playing_context_uri' => null, 'now_playing_track_id' => 't2']);
        $this->fakeSpotify(['track' => 't2', 'is_playing' => true, 'progress_ms' => 1000], []);

        app(PlaybackSync::class)->sync($room);

        $this->assertSame(self::CONTEXT, $room->refresh()->now_playing_context_uri);
    }

    public function test_the_end_of_a_playlist_with_repeat_off_is_recognised_and_hidden(): void
    {
        $room = $this->room();
        $this->parkedOnFirstTrack();

        app(PlaybackSync::class)->sync($room);

        $room->refresh();
        $this->assertTrue($room->playlistFinished());
        $this->assertNull($room->nowPlayingDetails(), 'the parked first track must not be shown as current');
        $this->assertSame('t1', $room->now_playing_track_id, 'but it stays stored so Play can restart the playlist');
    }

    public function test_the_dashboard_and_party_screen_say_the_playlist_ended_and_list_nothing_coming_up(): void
    {
        $room = $this->room();
        $this->parkedOnFirstTrack();
        app(PlaybackSync::class)->sync($room);

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room->refresh()])
            ->assertSee('End of the playlist')
            ->assertSee('Press play to start it again')
            ->assertDontSee('Alpha Anthem')
            ->assertDontSee('Bravo Ballad')
            ->assertDontSee('Coming up from the playlist');

        Livewire::test(PartyScreen::class, ['room' => $room])
            ->assertSee('End of the playlist')
            ->assertDontSee('Alpha Anthem')
            ->assertDontSee('Bravo Ballad');
    }

    public function test_play_restarts_the_playlist_inside_its_context_from_the_top(): void
    {
        $room = $this->room();
        $this->parkedOnFirstTrack();
        app(PlaybackSync::class)->sync($room);

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room->refresh()])->call('play');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/me/player/play')
            && $r['context_uri'] === self::CONTEXT
            && $r['offset'] === ['uri' => 'spotify:track:t1']
            && $r['position_ms'] === 0);

        $room->refresh();
        $this->assertTrue($room->is_playing);
        $this->assertFalse($room->playlistFinished(), 'playing again ends the finished state');
    }

    public function test_pausing_partway_through_a_song_is_not_mistaken_for_the_end(): void
    {
        $room = $this->room(['now_playing_track_id' => 't2']);
        $this->fakeSpotify(['track' => 't3', 'is_playing' => false, 'progress_ms' => 90000], []);

        app(PlaybackSync::class)->sync($room);

        $this->assertFalse($room->refresh()->playlistFinished());
    }

    public function test_it_is_not_the_end_when_repeat_is_on(): void
    {
        $room = $this->room();
        $this->fakeSpotify(['track' => 't1', 'is_playing' => false, 'progress_ms' => 0, 'repeat' => 'context'], []);

        app(PlaybackSync::class)->sync($room);

        $this->assertFalse($room->refresh()->playlistFinished());
    }

    public function test_the_finished_state_survives_further_syncs_until_something_plays(): void
    {
        $room = $this->room();
        $this->parkedOnFirstTrack();

        app(PlaybackSync::class)->sync($room);
        app(PlaybackSync::class)->sync($room->refresh());
        app(PlaybackSync::class)->sync($room->refresh());

        $this->assertTrue($room->refresh()->playlistFinished());

        // Stubs registered first win, so Spotify's answer is replaced with a fresh fake.
        Http::swap(new \Illuminate\Http\Client\Factory);
        $this->fakeSpotify(['track' => 't1', 'is_playing' => true, 'progress_ms' => 2000], ['t2', 't3']);
        app(PlaybackSync::class)->sync($room);

        $this->assertFalse($room->refresh()->playlistFinished());
    }

    public function test_on_the_last_track_with_repeat_off_the_padding_is_not_listed(): void
    {
        $room = $this->room();
        // What Spotify really returns on the last track: the whole playlist again, current track included.
        $this->fakeSpotify(['track' => 't3', 'is_playing' => true, 'progress_ms' => 100000], ['t1', 't2', 't3', 't1', 't2', 't3', 't1', 't2', 't3']);

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])
            ->assertSee('This is the last track of the playlist')
            ->assertDontSee('Coming up from the playlist')
            ->assertDontSee('Alpha Anthem')
            ->assertDontSee('Bravo Ballad');
    }

    public function test_mid_playlist_only_the_real_remainder_is_listed(): void
    {
        $room = $this->room(['now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha Anthem']);
        $this->fakeSpotify(['track' => 't1', 'is_playing' => true, 'progress_ms' => 100000], ['t2', 't3', 't1', 't2', 't3']);

        $html = Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])
            ->assertSee('Coming up from the playlist')
            ->assertSee('Bravo Ballad')
            ->assertSee('Charlie Chorus')
            ->html();

        $this->assertSame(1, substr_count($html, 'Bravo Ballad'), 'each upcoming track appears once, not once per wrap');
        $this->assertSame(1, substr_count($html, 'Charlie Chorus'));
    }

    public function test_clicking_a_coming_up_track_plays_it_inside_the_playlist_even_when_it_was_started_in_the_spotify_app(): void
    {
        // The room has no playlist of its own stored; the context is only known from Spotify.
        $room = $this->room(['now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha Anthem', 'fallback_playlist_uri' => null]);
        $this->fakeSpotify(['track' => 't1', 'is_playing' => true, 'progress_ms' => 100000], ['t2', 't3', 't1', 't2', 't3']);

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])
            ->call('playFromPlaylist', 't3')
            ->assertSet('controlError', '');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/me/player/play')
            && $r['context_uri'] === self::CONTEXT
            && $r['offset'] === ['uri' => 'spotify:track:t3']
            && $r['position_ms'] === 0);

        $room->refresh();
        $this->assertSame('t3', $room->now_playing_track_id);
        $this->assertSame('Charlie Chorus', $room->now_playing_name);
        $this->assertTrue($room->is_playing);
    }

    public function test_the_rendered_rows_are_wired_to_that_click(): void
    {
        $room = $this->room(['now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha Anthem']);
        $this->fakeSpotify(['track' => 't1', 'is_playing' => true, 'progress_ms' => 100000], ['t2', 't3', 't1', 't2', 't3']);

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])
            ->assertSeeHtml("wire:click=\"playFromPlaylist('t2')\"")
            ->assertSeeHtml("wire:click=\"playFromPlaylist('t3')\"");
    }

    public function test_a_track_that_is_not_in_the_list_cannot_be_jumped_to(): void
    {
        $room = $this->room(['now_playing_track_id' => 't1']);
        $this->fakeSpotify(['track' => 't1', 'is_playing' => true, 'progress_ms' => 100000], ['t2', 't3', 't1', 't2', 't3']);

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])
            ->call('playFromPlaylist', 'not-in-the-list')
            ->assertNotSet('controlError', '');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/me/player/play'));
    }

    public function test_with_no_playlist_or_album_playing_a_click_says_why_instead_of_doing_nothing(): void
    {
        $room = $this->room(['now_playing_context_uri' => null, 'fallback_playlist_uri' => null]);
        $this->fakeSpotify(['track' => 't3', 'is_playing' => true, 'progress_ms' => 1000], ['t1']);

        Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])
            ->call('playFromPlaylist', 't1')
            ->assertNotSet('controlError', '');
    }

    public function test_an_unreadable_playlist_order_still_trims_the_loop_where_the_track_comes_round_again(): void
    {
        $room = $this->room(['now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha Anthem']);
        $this->fakeSpotify(['track' => 't1', 'is_playing' => true, 'progress_ms' => 1000], ['t2', 't3', 't1', 't2', 't3'], orderReadable: false);

        $html = Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room])->html();

        $this->assertSame(1, substr_count($html, 'Bravo Ballad'));
        $this->assertSame(1, substr_count($html, 'Charlie Chorus'));
    }
}
