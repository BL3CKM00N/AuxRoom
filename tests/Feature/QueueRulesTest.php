<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\ShowRoom;
use App\Models\PlayedTrack;
use App\Models\QueueItem;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\RoomMembership;
use App\Services\Spotify\PlaybackSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** The host's rules for adding songs: limit per guest, duplicate guard, block list, explicit filter, overrides. */
class QueueRulesTest extends TestCase
{
    use RefreshDatabase;

    private Room $room;

    private User $host;

    private RoomMember $guest;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = User::factory()->create();
        SpotifyAccount::create([
            'user_id' => $this->host->id, 'client_id' => 'id', 'client_secret' => 'secret',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
        ]);
        $this->room = Room::create([
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $this->host->id, 'playback_provider_id' => $this->host->id,
            'is_playing' => true, 'now_playing_track_id' => 'playing', 'now_playing_name' => 'Playing Song',
            'now_playing_artist' => 'Someone', 'now_playing_duration_ms' => 180000, 'now_playing_position_ms' => 1000,
            'now_playing_started_at' => now(), 'now_playing_artists' => [['id' => 'ar-now', 'name' => 'Someone']],
        ])->refresh();

        $membership = app(RoomMembership::class);
        $membership->joinAsHost($this->room);
        ['guest_token' => $this->token] = $membership->joinAsGuest($this->room, 'Guest One');
        $this->guest = RoomMember::where('guest_token', $this->token)->firstOrFail();
        $this->guest->update(['approved_at' => now()]);

        Cache::flush();

        // A room that has been running: the watcher confirmed it a moment ago, so opening it asks Spotify nothing.
        \App\Services\Spotify\WatchState::markVerified($this->room);
        Http::fake([
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => [
                $this->apiTrack('s1', 'Song One', 'ar1', 'Artist One'),
                $this->apiTrack('s2', 'Song Two', 'ar2', 'Artist Two', explicit: true),
                $this->apiTrack('s3', 'Song Three', 'ar1', 'Artist One'),
                $this->apiTrack('s4', 'Song Four', 'ar4', 'Artist Four'),
            ]]]),
            'api.spotify.com/v1/me/player/queue*' => Http::response('', 204),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => [['id' => 'd1', 'name' => 'Laptop', 'type' => 'Computer', 'is_active' => true, 'supports_volume' => true]]]),
            'api.spotify.com/v1/me/player' => Http::response('', 204),
        ]);
    }

    private function apiTrack(string $id, string $name, string $artistId, string $artist, bool $explicit = false): array
    {
        return ['id' => $id, 'uri' => "spotify:track:{$id}", 'name' => $name, 'duration_ms' => 180000, 'explicit' => $explicit,
            'artists' => [['id' => $artistId, 'name' => $artist]], 'album' => ['images' => []]];
    }

    private function asGuest(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::withCookie(RoomMembership::cookieName($this->room), $this->token)
            ->test(ShowRoom::class, ['room' => $this->room]);
    }

    private function asHost(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($this->host)->test(ShowRoom::class, ['room' => $this->room]);
    }

    /** Searches, then adds the result at $index (the search fake always returns the same four songs, s1 to s4). */
    private function add($component, int $index, bool $force = false)
    {
        return $component->set('search', 'anything')->call('addToQueue', $index, $force);
    }

    public function test_with_no_rules_everything_can_be_added(): void
    {
        $component = $this->asGuest();
        $this->add($component, 0);
        $this->add($component, 1);

        $this->assertSame(2, QueueItem::count());
        $this->assertSame('', $component->get('controlErrorCode'));
    }

    public function test_the_limit_counts_waiting_songs_and_frees_up_when_one_plays(): void
    {
        $this->room->update(['queue_limit_per_guest' => 1]);
        $component = $this->asGuest();

        $this->add($component, 0);
        $this->assertSame(1, QueueItem::count());

        $this->add($component, 3)->assertSet('controlErrorCode', 'QUEUE-LIMIT');
        $this->assertSame(1, QueueItem::count());

        QueueItem::first()->update(['played_at' => now()]);

        $this->add($component, 3);
        $this->assertSame(2, QueueItem::count());
    }

    public function test_the_limit_is_per_guest(): void
    {
        $this->room->update(['queue_limit_per_guest' => 1]);
        $this->add($this->asGuest(), 0);

        // Someone else's waiting song doesn't count against this guest.
        QueueItem::first()->update(['added_by_id' => RoomMember::where('role', 'host')->value('id')]);

        $this->add($this->asGuest(), 3);
        $this->assertSame(2, QueueItem::count());
    }

    public function test_the_duplicate_guard_covers_playing_waiting_and_recently_played_songs(): void
    {
        $this->room->update(['duplicate_guard_tracks' => 2, 'now_playing_track_id' => 's1']);

        $this->add($this->asGuest(), 0)->assertSet('controlErrorCode', 'QUEUE-DUPLICATE');

        $this->room->update(['now_playing_track_id' => 'playing']);
        PlayedTrack::create(['room_id' => $this->room->id, 'spotify_track_id' => 's4', 'played_at' => now()->subMinutes(9)]);
        PlayedTrack::create(['room_id' => $this->room->id, 'spotify_track_id' => 'x1', 'played_at' => now()->subMinutes(6)]);
        PlayedTrack::create(['room_id' => $this->room->id, 'spotify_track_id' => 'x2', 'played_at' => now()->subMinutes(3)]);

        // s4 is third from last: outside a window of 2.
        $component = $this->asGuest();
        $this->add($component, 3);
        $this->assertSame(1, QueueItem::where('spotify_track_id', 's4')->count());

        // x2 is the last played: inside it.
        PlayedTrack::create(['room_id' => $this->room->id, 'spotify_track_id' => 's3', 'played_at' => now()]);
        $this->add($component, 2)->assertSet('controlErrorCode', 'QUEUE-DUPLICATE');

        // And a song already waiting can't be added twice.
        $this->add($component, 3)->assertSet('controlErrorCode', 'QUEUE-DUPLICATE');
        $this->assertSame(1, QueueItem::where('spotify_track_id', 's4')->count());
    }

    public function test_blocked_tracks_and_artists_are_refused(): void
    {
        $this->room->blockedItems()->create(['kind' => 'track', 'spotify_id' => 's4', 'name' => 'Song Four']);
        $this->room->blockedItems()->create(['kind' => 'artist', 'spotify_id' => 'ar2', 'name' => 'Artist Two']);
        $component = $this->asGuest();

        $this->add($component, 3)->assertSet('controlErrorCode', 'QUEUE-BLOCKED');
        $this->add($component, 1)->assertSet('controlErrorCode', 'QUEUE-BLOCKED');
        $this->assertSame(0, QueueItem::count());

        $this->add($component, 0);
        $this->assertSame(1, QueueItem::count());
    }

    public function test_the_explicit_filter_uses_spotifys_label(): void
    {
        $this->room->update(['block_explicit' => true]);
        $component = $this->asGuest();

        $this->add($component, 1)->assertSet('controlErrorCode', 'QUEUE-EXPLICIT');
        $this->add($component, 0);
        $this->assertSame(1, QueueItem::count());
    }

    public function test_the_host_is_warned_and_can_add_anyway(): void
    {
        $this->room->update(['block_explicit' => true]);
        $component = $this->asHost();

        $this->add($component, 1);
        $this->assertSame(0, QueueItem::count());
        $this->assertSame('QUEUE-EXPLICIT', $component->get('ruleWarning')['code']);

        $component->call('addToQueue', 1, true);
        $this->assertSame(1, QueueItem::count());
        $this->assertNull($component->get('ruleWarning'));
        $this->assertDatabaseHas('activity_events', ['type' => 'queued', 'message' => RoomMember::where('role', 'host')->value('display_name').' added "Song Two" to the queue (past a queue rule).']);
    }

    public function test_a_guest_cannot_force_a_song_past_a_rule(): void
    {
        $this->room->update(['block_explicit' => true]);

        $this->add($this->asGuest(), 1, true)->assertSet('controlErrorCode', 'QUEUE-EXPLICIT');
        $this->assertSame(0, QueueItem::count());
    }

    public function test_an_exempt_guest_skips_the_rules(): void
    {
        $this->room->update(['block_explicit' => true, 'queue_limit_per_guest' => 1]);
        $this->guest->update(['queue_rules_exempt' => true]);
        $component = $this->asGuest();

        $this->add($component, 1);
        $this->add($component, 0);

        $this->assertSame(2, QueueItem::count());
    }

    public function test_only_the_host_changes_the_rules(): void
    {
        $guest = $this->asGuest();
        $guest->call('setQueueLimit', 1)->call('setDuplicateGuard', 5)->call('toggleBlockExplicit')->call('blockNowPlayingTrack');
        $guest->call('setQueueRulesExempt', $this->guest->id, true);

        $room = $this->room->fresh();
        $this->assertNull($room->queue_limit_per_guest);
        $this->assertNull($room->duplicate_guard_tracks);
        $this->assertFalse($room->block_explicit);
        $this->assertSame(0, $room->blockedItems()->count());
        $this->assertFalse($this->guest->fresh()->queue_rules_exempt);

        $host = $this->asHost();
        $host->call('setQueueLimit', 3)->call('setDuplicateGuard', 10)->call('toggleBlockExplicit')->call('setQueueRulesExempt', $this->guest->id, true);

        $room = $this->room->fresh();
        $this->assertSame(3, $room->queue_limit_per_guest);
        $this->assertSame(10, $room->duplicate_guard_tracks);
        $this->assertTrue($room->block_explicit);
        $this->assertTrue($this->guest->fresh()->queue_rules_exempt);

        // Only the offered choices are accepted.
        $host->call('setQueueLimit', 99)->call('setDuplicateGuard', 7);
        $this->assertSame(3, $this->room->fresh()->queue_limit_per_guest);
        $this->assertSame(10, $this->room->fresh()->duplicate_guard_tracks);
    }

    public function test_the_host_blocks_and_unblocks_what_is_playing(): void
    {
        $host = $this->asHost();
        $host->call('blockNowPlayingTrack')->call('blockNowPlayingArtist', 'ar-now')->call('blockNowPlayingArtist', 'not-on-this-song');

        $this->assertSame(['track', 'artist'], $this->room->blockedItems()->orderBy('id')->pluck('kind')->all());

        $host->call('blockNowPlayingTrack');
        $this->assertSame(2, $this->room->blockedItems()->count(), 'blocking twice does not duplicate');

        $host->call('unblock', $this->room->blockedItems()->first()->id);
        $this->assertSame(1, $this->room->blockedItems()->count());
    }

    public function test_played_tracks_are_recorded_once_from_sync_and_prediction(): void
    {
        $state = [
            'is_playing' => true, 'progress_ms' => 0, 'track_id' => 'new1', 'device_id' => 'd1', 'name' => 'New One', 'artist' => 'Band',
            'artists' => [['id' => 'b1', 'name' => 'Band']], 'explicit' => true, 'album_art_url' => null, 'duration_ms' => 100000,
            'context_uri' => null, 'shuffle_enabled' => false, 'smart_shuffle_enabled' => false, 'supports_volume' => true,
            'volume_percent' => 50, 'repeat_mode' => 'off',
        ];

        app(PlaybackSync::class)->applyState($this->room, $state);
        app(PlaybackSync::class)->applyState($this->room->fresh(), $state);

        $this->assertSame(['new1'], PlayedTrack::pluck('spotify_track_id')->all());
        $room = $this->room->fresh();
        $this->assertSame([['id' => 'b1', 'name' => 'Band']], $room->now_playing_artists);
        $this->assertTrue($room->now_playing_explicit);
    }

    public function test_the_played_track_history_stays_small(): void
    {
        for ($i = 1; $i <= 230; $i++) {
            PlayedTrack::record($this->room, "t{$i}");
        }

        $this->assertLessThanOrEqual(201, PlayedTrack::where('room_id', $this->room->id)->count());
        $this->assertSame('t230', PlayedTrack::where('room_id', $this->room->id)->latest('id')->value('spotify_track_id'));
    }

    public function test_guests_see_which_rules_are_on(): void
    {
        $this->room->update(['queue_limit_per_guest' => 3, 'block_explicit' => true]);

        $this->asGuest()->assertSee('Up to 3 songs waiting per person')->assertSee('No explicit songs');
    }

    public function test_the_rules_card_is_in_room_settings_for_the_host_only(): void
    {
        $this->room->blockedItems()->create(['kind' => 'artist', 'spotify_id' => 'ar9', 'name' => 'Blocked Band']);

        // The guest first: actingAs() in asHost() would stay signed in for the rest of the test.
        $this->asGuest()->call('setTab', 'hub')->assertDontSee('Queue rules')->assertDontSee('Blocked Band');
        $this->asHost()->call('setTab', 'hub')->assertSee('Queue rules')->assertSee('Blocked Band')->assertSee('Block this song');
    }

    public function test_the_guests_tab_offers_the_exempt_toggle(): void
    {
        $this->asHost()->call('setTab', 'guests')->assertSee('Exempt from queue rules');
    }

    public function test_the_rule_error_codes_have_guides(): void
    {
        foreach (['QUEUE-LIMIT', 'QUEUE-DUPLICATE', 'QUEUE-BLOCKED', 'QUEUE-EXPLICIT'] as $code) {
            $this->assertNotNull(\App\Support\Errors\ErrorCatalog::find($code));
        }
    }
}
