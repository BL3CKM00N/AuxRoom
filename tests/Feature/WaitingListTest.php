<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\ShowRoom;
use App\Models\QueueItem;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\Queue\QueueFeeder;
use App\Services\RoomMembership;
use App\Services\Spotify\UpcomingQueue;
use App\Services\Spotify\WatchState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Guests' songs wait on AuxRoom's list and go to Spotify one at a time: removal, rotation, skips from either side. */
class WaitingListTest extends TestCase
{
    use RefreshDatabase;

    private Room $room;

    private User $host;

    private RoomMember $hostMember;

    /** @var array<string, array{member: RoomMember, token: string}> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = User::factory()->create();
        SpotifyAccount::create([
            'user_id' => $this->host->id, 'client_id' => 'id', 'client_secret' => 'secret', 'access_token' => 'token',
            'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(), 'active_device_id' => 'd1',
        ]);
        $this->room = Room::create([
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $this->host->id, 'playback_provider_id' => $this->host->id,
            'is_playing' => true, 'now_playing_track_id' => 'playing', 'now_playing_name' => 'Playing Song', 'now_playing_artist' => 'Someone',
            'now_playing_duration_ms' => 200000, 'now_playing_position_ms' => 10000, 'now_playing_started_at' => now()->subSeconds(10),
            'waiting_list_enabled' => true, 'repeat_mode' => 'off',
        ])->refresh();

        $this->hostMember = app(RoomMembership::class)->joinAsHost($this->room);
        $this->person('anna');
        $this->person('ben');

        Cache::flush();
        WatchState::beat();
        Http::fake([
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => array_map(fn ($i) => $this->apiTrack("s{$i}"), [1, 2, 3, 4])]]),
            'api.spotify.com/v1/me/player/queue*' => fn (Request $request) => $request->method() === 'POST' ? Http::response('', 204) : Http::response(['queue' => []]),
            'api.spotify.com/v1/me/player/next*' => Http::response('', 204),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => [['id' => 'd1', 'name' => 'Laptop', 'type' => 'Computer', 'is_active' => true, 'supports_volume' => true]]]),
            'api.spotify.com/v1/me/player' => Http::response('', 204),
        ]);
    }

    private function apiTrack(string $id): array
    {
        return ['id' => $id, 'uri' => "spotify:track:{$id}", 'name' => "Song {$id}", 'duration_ms' => 180000, 'explicit' => false,
            'artists' => [['id' => 'ar', 'name' => 'Artist']], 'album' => ['images' => []]];
    }

    private function person(string $key, string $role = 'guest'): void
    {
        ['guest_token' => $token] = app(RoomMembership::class)->joinAsGuest($this->room, ucfirst($key));
        $member = RoomMember::where('guest_token', $token)->firstOrFail();
        $member->update(['role' => $role, 'approved_at' => now()]);
        $this->people[$key] = ['member' => $member, 'token' => $token];
    }

    private function as(string $who)
    {
        return Livewire::withCookie(RoomMembership::cookieName($this->room), $this->people[$who]['token'])
            ->test(ShowRoom::class, ['room' => $this->room]);
    }

    private function id(string $who): int
    {
        return $this->people[$who]['member']->id;
    }

    /** @return array<int, string> the requests sent to Spotify, as "METHOD last-path-part?query" */
    private function sent(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => $r->method() === 'POST')
            ->map(fn (Request $r) => basename(parse_url($r->url(), PHP_URL_PATH)).'?'.(parse_url($r->url(), PHP_URL_QUERY) ?? ''))
            ->values()->all();
    }

    private function item(string $trackId, string $by, array $extra = []): QueueItem
    {
        static $position = 0;

        return $this->room->queueItems()->create($extra + [
            'added_by_id' => $this->id($by), 'spotify_track_id' => $trackId, 'name' => "Song {$trackId}", 'artist' => 'Artist',
            'duration_ms' => 180000, 'position' => ++$position,
        ]);
    }

    private function feeder(): QueueFeeder
    {
        return app(QueueFeeder::class);
    }

    public function test_the_first_song_goes_straight_in_and_the_rest_wait(): void
    {
        $anna = $this->as('anna');
        $anna->set('search', 'x')->call('addToQueue', 0);
        $anna->set('search', 'x')->call('addToQueue', 1);
        $anna->set('search', 'x')->call('addToQueue', 2);

        $items = QueueItem::orderBy('id')->get();
        $this->assertNotNull($items[0]->released_at, 'nothing was next up, so it is handed over at once');
        $this->assertNull($items[1]->released_at);
        $this->assertNull($items[2]->released_at);
        $this->assertSame(['queue?uri=spotify%3Atrack%3As1&device_id=d1'], $this->sent());
    }

    public function test_without_the_watcher_or_with_the_list_off_songs_go_straight_to_spotify(): void
    {
        Cache::forget('watcher:heartbeat');
        $anna = $this->as('anna');
        $anna->set('search', 'x')->call('addToQueue', 0);
        $anna->set('search', 'x')->call('addToQueue', 1);
        $this->assertSame(2, QueueItem::whereNotNull('released_at')->count(), 'no watcher to hand it over later');

        QueueItem::query()->delete();
        WatchState::beat();
        $this->room->update(['waiting_list_enabled' => false]);
        $anna = $this->as('anna');
        $anna->set('search', 'x')->call('addToQueue', 0);
        $anna->set('search', 'x')->call('addToQueue', 1);
        $this->assertSame(2, QueueItem::whereNotNull('released_at')->count());
    }

    public function test_when_nothing_is_playing_the_first_song_starts_it(): void
    {
        $this->room->update(['is_playing' => false, 'now_playing_track_id' => null]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.spotify.com/*' => Http::response(['devices' => [['id' => 'd1', 'name' => 'Laptop', 'type' => 'Computer', 'is_active' => true, 'supports_volume' => true]], 'tracks' => ['items' => [$this->apiTrack('s1')]]])]);

        $this->as('anna')->set('search', 'x')->call('addToQueue', 0);

        $this->assertNotNull(QueueItem::first()->released_at);
    }

    public function test_the_next_song_is_handed_over_when_spotifys_queue_runs_dry(): void
    {
        $played = $this->item('s1', 'anna', ['released_at' => now()->subMinute(), 'played_at' => now()]);
        $waiting = $this->item('s2', 'anna');

        $this->assertTrue($this->feeder()->feed($this->room));
        $this->assertNotNull($waiting->fresh()->released_at);

        $this->assertFalse($this->feeder()->feed($this->room->fresh()), 'one is already next up');
        $this->assertCount(1, $this->sent());
    }

    public function test_it_hands_over_nothing_when_the_list_is_off_or_nothing_waits(): void
    {
        $this->assertFalse($this->feeder()->feed($this->room), 'nothing waiting');

        $this->item('s2', 'anna');
        $this->room->update(['waiting_list_enabled' => false]);
        $this->assertFalse($this->feeder()->feed($this->room->fresh()));
        $this->assertSame([], $this->sent());
    }

    public function test_a_failing_spotify_is_not_asked_again_every_second(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.spotify.com/*' => Http::response('', 500)]);
        $this->item('s2', 'anna');

        $this->assertFalse($this->feeder()->feed($this->room));
        $this->assertFalse($this->feeder()->feed($this->room));

        $this->assertCount(1, $this->sent());
        $this->assertNull(QueueItem::first()->released_at);
    }

    public function test_waiting_songs_go_out_first_come_first_served_by_default(): void
    {
        $a1 = $this->item('s1', 'anna');
        $a2 = $this->item('s2', 'anna');
        $b1 = $this->item('s3', 'ben');

        $this->assertSame([$a1->id, $a2->id, $b1->id], $this->feeder()->waiting($this->room)->pluck('id')->all());
    }

    public function test_rotation_takes_turns_between_guests(): void
    {
        $this->room->update(['rotation_enabled' => true]);
        $a1 = $this->item('s1', 'anna');
        $a2 = $this->item('s2', 'anna');
        $a3 = $this->item('s3', 'anna');
        $b1 = $this->item('s4', 'ben');
        $b2 = $this->item('s5', 'ben');

        $this->assertSame([$a1->id, $b1->id, $a2->id, $b2->id, $a3->id], $this->feeder()->waiting($this->room)->pluck('id')->all());
    }

    public function test_rotation_puts_whoever_went_longest_ago_first(): void
    {
        $this->room->update(['rotation_enabled' => true]);
        // Anna's song went out a minute ago, Ben's ten minutes ago: Ben is up.
        $this->item('x1', 'anna', ['released_at' => now()->subMinute(), 'played_at' => now()]);
        $this->item('x2', 'ben', ['released_at' => now()->subMinutes(10), 'played_at' => now()]);
        $a = $this->item('s1', 'anna');
        $b = $this->item('s2', 'ben');

        $this->assertSame([$b->id, $a->id], $this->feeder()->waiting($this->room)->pluck('id')->all());
    }

    public function test_a_newcomer_goes_before_a_guest_who_already_had_songs_played(): void
    {
        $this->room->update(['rotation_enabled' => true]);
        $this->item('x1', 'anna', ['released_at' => now()->subMinute(), 'played_at' => now()]);
        $a = $this->item('s1', 'anna');
        $b = $this->item('s2', 'ben');

        $this->assertSame($b->id, $this->feeder()->next($this->room)->id);
        $this->assertSame([$b->id, $a->id], $this->feeder()->waiting($this->room)->pluck('id')->all());
    }

    public function test_a_guest_removes_their_own_waiting_song_and_nobody_elses(): void
    {
        $mine = $this->item('s1', 'anna');
        $theirs = $this->item('s2', 'ben');

        $anna = $this->as('anna');
        $anna->call('removeFromQueue', $theirs->id);
        $this->assertNotNull($theirs->fresh());

        $anna->call('removeFromQueue', $mine->id);
        $this->assertNull(QueueItem::find($mine->id), 'a waiting song is simply deleted');
        $this->assertSame([], $this->sent(), 'nothing was ever sent to Spotify');
    }

    public function test_the_host_and_a_co_host_remove_any_song(): void
    {
        $this->person('cohost', 'cohost');
        $one = $this->item('s1', 'anna');
        $two = $this->item('s2', 'ben');

        $this->as('cohost')->call('removeFromQueue', $one->id);
        Livewire::actingAs($this->host)->test(ShowRoom::class, ['room' => $this->room])->call('removeFromQueue', $two->id);

        $this->assertSame(0, QueueItem::count());
    }

    public function test_a_song_already_in_spotify_is_hidden_and_the_next_one_is_handed_over(): void
    {
        $nextUp = $this->item('s1', 'anna', ['released_at' => now()]);
        $waiting = $this->item('s2', 'ben');
        WatchState::putQueue($this->room, [['id' => 's1', 'uri' => 'spotify:track:s1', 'name' => 'Song s1', 'artist' => 'Artist', 'album_art_url' => null, 'duration_ms' => 180000]]);

        $this->as('anna')->call('removeFromQueue', $nextUp->id);

        $this->assertNotNull($nextUp->fresh()->removed_at, 'it stays in Spotify, so it is marked, not deleted');
        $this->assertNotNull($waiting->fresh()->released_at, 'something real has to follow it');

        // Spotify still lists it (and now the next song too) until it plays.
        $track = fn ($id) => ['id' => $id, 'uri' => "spotify:track:{$id}", 'name' => "Song {$id}", 'artist' => 'Artist', 'album_art_url' => null, 'duration_ms' => 180000];
        WatchState::putQueue($this->room, [$track('s1'), $track('s2')]);

        $shown = array_column(app(UpcomingQueue::class)->forRoom($this->room->fresh()), 'id');
        $this->assertSame(['s2'], $shown);
    }

    public function test_a_removed_song_is_skipped_the_moment_it_starts_and_only_once(): void
    {
        $removed = $this->item('s1', 'anna', ['released_at' => now()->subSeconds(30), 'removed_at' => now()->subSeconds(20), 'played_at' => now()]);
        $this->item('s2', 'ben');
        $this->room->update(['now_playing_track_id' => 's1', 'now_playing_queue_item_id' => $removed->id]);

        $this->feeder()->afterSync($this->room->fresh());
        $this->feeder()->afterSync($this->room->fresh());

        $this->assertNotNull($removed->fresh()->skipped_at);
        $sent = $this->sent();
        $this->assertSame(1, count(array_filter($sent, fn ($s) => str_starts_with($s, 'next'))), 'skipped once');
        $this->assertTrue(collect($sent)->contains(fn ($s) => str_starts_with($s, 'queue?uri=spotify%3Atrack%3As2')), 'the next real song was handed over first');
        $this->assertLessThan(array_search(true, array_map(fn ($s) => str_starts_with($s, 'next'), $sent)), array_search(true, array_map(fn ($s) => str_starts_with($s, 'queue'), $sent)));
    }

    private function playlistSongJustStartedAfterASkipElsewhere(): void
    {
        $this->room->update(['now_playing_track_id' => 'p1', 'now_playing_queue_item_id' => null, 'is_playing' => true, 'last_local_command_at' => now()->subMinute()]);
        Cache::put("track-change:{$this->room->id}", ['track' => 'p1', 'early' => true], 15);
    }

    public function test_a_playlist_song_that_took_over_after_a_skip_elsewhere_is_skipped_for_the_waiting_song(): void
    {
        $this->item('s1', 'anna');
        $this->playlistSongJustStartedAfterASkipElsewhere();

        $this->feeder()->afterSync($this->room->fresh());
        $this->feeder()->afterSync($this->room->fresh());

        $sent = $this->sent();
        $this->assertCount(2, $sent, 'handed over, then skipped, and not again');
        $this->assertStringStartsWith('queue', $sent[0]);
        $this->assertStringStartsWith('next', $sent[1]);
    }

    public function test_it_does_not_skip_a_song_the_host_just_chose(): void
    {
        $this->item('s1', 'anna');
        $this->playlistSongJustStartedAfterASkipElsewhere();
        $this->room->update(['last_local_command_at' => now()]);

        $this->feeder()->afterSync($this->room->fresh());

        $this->assertSame([], array_filter($this->sent(), fn ($s) => str_starts_with($s, 'next')), 'a command from AuxRoom was the cause');
    }

    public function test_it_does_not_skip_a_song_after_one_that_played_to_the_end(): void
    {
        $this->item('s1', 'anna');
        $this->playlistSongJustStartedAfterASkipElsewhere();
        Cache::put("track-change:{$this->room->id}", ['track' => 'p1', 'early' => false], 15);

        $this->feeder()->afterSync($this->room->fresh());

        $this->assertSame([], array_filter($this->sent(), fn ($s) => str_starts_with($s, 'next')), 'the song before it was not cut short');
        $this->assertCount(1, $this->sent(), 'the waiting song is still handed over, to play after the playlist song');
    }

    public function test_the_waiting_list_shows_after_what_is_queued_and_before_the_playlist(): void
    {
        $this->item('q1', 'anna', ['released_at' => now()]);
        $this->item('w1', 'ben');
        $this->item('w2', 'anna');
        WatchState::putQueue($this->room, array_map(fn ($id) => ['id' => $id, 'uri' => "spotify:track:{$id}", 'name' => "Song {$id}", 'artist' => 'Artist', 'album_art_url' => null, 'duration_ms' => 180000], ['q1', 'p1', 'p2']));

        $queue = collect(app(UpcomingQueue::class)->forRoom($this->room));

        $this->assertSame(['q1', 'w1', 'w2', 'p1', 'p2'], $queue->pluck('id')->all());
        $this->assertSame([false, true, true, false, false], $queue->pluck('waiting')->all());
        $this->assertSame([true, false, false, false, false], $queue->pluck('locked')->all());
    }

    public function test_a_song_handed_over_a_moment_ago_still_shows_before_spotifys_answer_lists_it(): void
    {
        $this->item('q1', 'anna', ['released_at' => now()]);
        WatchState::putQueue($this->room, []);

        $this->assertSame(['q1'], array_column(app(UpcomingQueue::class)->forRoom($this->room), 'id'));
    }

    public function test_the_queue_tab_lets_people_remove_the_right_songs(): void
    {
        $this->item('w1', 'anna');
        $this->item('w2', 'ben');
        WatchState::putQueue($this->room, []);

        $anna = $this->as('anna')->html();
        $this->assertStringContainsString('removeFromQueue('.QueueItem::where('spotify_track_id', 'w1')->value('id').')', $anna);
        $this->assertStringNotContainsString('removeFromQueue('.QueueItem::where('spotify_track_id', 'w2')->value('id').')', $anna);
        $this->assertStringContainsString('Waiting', $anna);
    }

    public function test_switching_the_list_off_sends_everything_waiting_to_spotify_in_order(): void
    {
        $this->room->update(['rotation_enabled' => true]);
        $this->item('s1', 'anna');
        $this->item('s2', 'anna');
        $this->item('s3', 'ben');

        Livewire::actingAs($this->host)->test(ShowRoom::class, ['room' => $this->room])->call('toggleWaitingList');

        $this->assertFalse($this->room->fresh()->waiting_list_enabled);
        $this->assertSame(0, QueueItem::whereNull('released_at')->count());
        $this->assertSame(['s1', 's3', 's2'], array_map(fn ($s) => urldecode(explode('track%3A', explode('&', $s)[0])[1]), $this->sent()), 'in the order rotation would have used');
    }

    public function test_only_the_host_turns_the_list_and_rotation_on_and_rotation_needs_the_list(): void
    {
        $this->as('anna')->call('toggleWaitingList')->call('toggleRotation');
        $this->person('cohost', 'cohost');
        $this->as('cohost')->call('toggleWaitingList')->call('toggleRotation');

        $room = $this->room->fresh();
        $this->assertTrue($room->waiting_list_enabled);
        $this->assertFalse($room->rotation_enabled);

        $host = Livewire::actingAs($this->host)->test(ShowRoom::class, ['room' => $this->room]);
        $host->call('toggleRotation');
        $this->assertTrue($this->room->fresh()->rotation_enabled);

        Room::whereKey($this->room->id)->update(['waiting_list_enabled' => false, 'rotation_enabled' => false]);
        $host->call('toggleRotation');
        $this->assertFalse($this->room->fresh()->rotation_enabled, 'nothing to rotate without the list');
    }

    public function test_skipping_hands_over_a_song_first_so_the_skip_lands_on_it(): void
    {
        $this->item('s1', 'anna');
        Livewire::actingAs($this->host)->test(ShowRoom::class, ['room' => $this->room])->call('skip');

        $sent = $this->sent();
        $this->assertStringStartsWith('queue?uri=spotify%3Atrack%3As1', $sent[0]);
        $this->assertStringStartsWith('next', $sent[1]);
    }

    public function test_songs_that_never_went_to_spotify_do_not_count_as_played_when_the_same_track_plays(): void
    {
        $this->item('s1', 'anna'); // waiting: not in Spotify's queue

        app(\App\Services\Spotify\PlaybackSync::class)->applyState($this->room, [
            'is_playing' => true, 'progress_ms' => 0, 'track_id' => 's1', 'device_id' => 'd', 'name' => 'Song s1', 'artist' => 'Artist',
            'artists' => [], 'explicit' => false, 'album_art_url' => null, 'duration_ms' => 180000, 'context_uri' => null,
            'shuffle_enabled' => false, 'smart_shuffle_enabled' => false, 'supports_volume' => true, 'volume_percent' => 50, 'repeat_mode' => 'off',
        ]);

        $this->assertNull(QueueItem::first()->played_at, 'the playlist played it; the waiting song is still waiting');
    }

    public function test_removed_songs_do_not_count_toward_the_limit_or_the_duplicate_guard(): void
    {
        $this->room->update(['queue_limit_per_guest' => 1, 'duplicate_guard_tracks' => 5]);
        $this->item('s1', 'anna', ['released_at' => now(), 'removed_at' => now()]);

        $anna = $this->as('anna');
        $anna->set('search', 'x')->call('addToQueue', 0)->assertSet('controlErrorCode', '');
        $this->assertSame(1, QueueItem::whereNull('removed_at')->count());
    }
}
