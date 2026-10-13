<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\ShowRoom;
use App\Models\QueueItem;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\RoomMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** A co-host is a trusted guest who helps run the room, without being able to change the room itself. */
class CohostTest extends TestCase
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
            'user_id' => $this->host->id, 'client_id' => 'id', 'client_secret' => 'secret',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
        ]);
        $this->room = Room::create([
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $this->host->id, 'playback_provider_id' => $this->host->id,
            'is_playing' => true, 'now_playing_track_id' => 'playing', 'now_playing_name' => 'Playing Song', 'now_playing_artist' => 'Someone',
            'now_playing_duration_ms' => 180000, 'now_playing_position_ms' => 1000, 'now_playing_started_at' => now(),
            'is_private' => true,
        ])->refresh();

        $this->hostMember = app(RoomMembership::class)->joinAsHost($this->room);
        $this->person('cohost', approved: true, role: 'cohost');
        $this->person('other', approved: true, role: 'cohost');
        $this->person('guest', approved: true);
        $this->person('pending', approved: false);

        Cache::flush();
        Http::fake([
            'api.spotify.com/v1/me/player/pause*' => Http::response('', 204),
            'api.spotify.com/v1/me/player/next*' => Http::response('', 204),
            'api.spotify.com/v1/me/player/queue*' => Http::response('', 204),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => [['id' => 'd1', 'name' => 'Laptop', 'type' => 'Computer', 'is_active' => true, 'supports_volume' => true]]]),
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => [
                ['id' => 's1', 'uri' => 'spotify:track:s1', 'name' => 'Song One', 'duration_ms' => 1000, 'explicit' => true, 'artists' => [['id' => 'a', 'name' => 'A']], 'album' => ['images' => []]],
            ]]]),
            'api.spotify.com/v1/me/player' => Http::response('', 204),
        ]);
    }

    private function person(string $key, bool $approved, string $role = 'guest'): void
    {
        ['guest_token' => $token] = app(RoomMembership::class)->joinAsGuest($this->room, ucfirst($key));
        $member = RoomMember::where('guest_token', $token)->firstOrFail();
        $member->update(['role' => $role, 'approved_at' => $approved ? now() : null]);
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

    private function member(string $who): RoomMember
    {
        return $this->people[$who]['member']->fresh();
    }

    public function test_the_host_picks_a_co_host_from_approved_guests_only(): void
    {
        $host = Livewire::actingAs($this->host)->test(ShowRoom::class, ['room' => $this->room]);

        $host->call('makeCohost', $this->id('guest'));
        $this->assertSame('cohost', $this->member('guest')->role);

        $host->call('makeCohost', $this->id('pending'));
        $this->assertSame('guest', $this->member('pending')->role, 'someone not yet let in cannot be promoted');

        $host->call('makeCohost', $this->hostMember->id);
        $this->assertSame('host', $this->hostMember->fresh()->role);
    }

    public function test_only_the_host_promotes_and_demotes(): void
    {
        $this->as('cohost')->call('makeCohost', $this->id('guest'))->call('removeCohost', $this->id('other'));
        $this->as('guest')->call('makeCohost', $this->id('guest'));

        $this->assertSame('guest', $this->member('guest')->role);
        $this->assertSame('cohost', $this->member('other')->role);

        Livewire::actingAs($this->host)->test(ShowRoom::class, ['room' => $this->room])->call('removeCohost', $this->id('other'));
        $this->assertSame('guest', $this->member('other')->role);
    }

    public function test_a_co_host_controls_playback_without_per_guest_permissions(): void
    {
        $cohost = $this->as('cohost');

        $this->assertTrue($cohost->instance()->canGuest('guests_can_skip'));
        $this->assertTrue($cohost->instance()->canGuest('guests_can_set_volume'));

        $cohost->call('pause')->assertSet('controlErrorCode', '');
        $cohost->call('skip')->assertSet('controlErrorCode', '');

        // A plain guest with no permissions is refused.
        $this->as('guest')->call('skip')->assertSet('controlErrorCode', 'ROOM-NO-PERMISSION');
    }

    public function test_a_co_host_ignores_the_queue_lock_and_the_location_check(): void
    {
        $this->room->update(['guests_can_add_to_queue' => false, 'location_enforced' => true, 'location_lat' => 52.0, 'location_lng' => 4.0, 'location_radius_m' => 50]);

        $cohost = $this->as('cohost');
        $this->assertTrue($cohost->instance()->canGuest('guests_can_add_to_queue'));
        $this->assertTrue($this->member('cohost')->passesLocationCheck());

        $cohost->set('search', 'x')->call('addToQueue', 0);
        $this->assertSame(1, QueueItem::count());

        $this->assertFalse($this->as('guest')->instance()->canGuest('guests_can_add_to_queue'));
    }

    public function test_a_co_host_approves_denies_and_removes_guests(): void
    {
        $this->person('pending2', approved: false);
        $cohost = $this->as('cohost');

        $cohost->call('approveMember', $this->id('pending'));
        $this->assertNotNull($this->member('pending')->approved_at);

        $cohost->call('denyMember', $this->id('pending2'));
        $this->assertNotNull($this->member('pending2')->left_at);

        $cohost->call('kickMember', $this->id('guest'));
        $this->assertNotNull($this->member('guest')->left_at);
    }

    public function test_a_co_host_cannot_remove_the_host_or_another_co_host(): void
    {
        $cohost = $this->as('cohost');
        $cohost->call('kickMember', $this->hostMember->id)->call('kickMember', $this->id('other'));

        $this->assertNull($this->hostMember->fresh()->left_at);
        $this->assertNull($this->member('other')->left_at);

        Livewire::actingAs($this->host)->test(ShowRoom::class, ['room' => $this->room])->call('kickMember', $this->id('other'));
        $this->assertNotNull($this->member('other')->left_at, 'the host can');
    }

    public function test_a_co_host_cannot_change_the_room(): void
    {
        $cohost = $this->as('cohost');

        $cohost->call('togglePrivate')->call('emergencyStopQueue')->call('closeRoom')->call('revokeAllAccess')
            ->call('setQueueLimit', 1)->call('toggleBlockExplicit')->call('blockNowPlayingTrack')
            ->call('setMemberPermission', $this->id('guest'), 'guests_can_skip', true)
            ->call('setQueueRulesExempt', $this->id('guest'), true)
            ->call('grantLocationException', $this->id('guest'));

        $room = $this->room->fresh();
        $this->assertTrue($room->is_private);
        $this->assertTrue($room->guests_can_add_to_queue);
        $this->assertNull($room->closed_at);
        $this->assertNull($room->queue_limit_per_guest);
        $this->assertFalse($room->block_explicit);
        $this->assertSame(0, $room->blockedItems()->count());
        $this->assertFalse((bool) $this->member('guest')->can_skip);
        $this->assertFalse($this->member('guest')->queue_rules_exempt);
        $this->assertNull($this->member('guest')->left_at);
        $this->assertNull($this->member('cohost')->left_at);
    }

    public function test_a_co_host_is_warned_by_queue_rules_and_can_add_anyway(): void
    {
        $this->room->update(['block_explicit' => true]);
        $cohost = $this->as('cohost');

        $cohost->set('search', 'x')->call('addToQueue', 0);
        $this->assertSame(0, QueueItem::count());
        $this->assertSame('QUEUE-EXPLICIT', $cohost->get('ruleWarning')['code']);

        $cohost->call('addToQueue', 0, true);
        $this->assertSame(1, QueueItem::count());
    }

    public function test_revoking_all_access_removes_co_hosts_too(): void
    {
        Livewire::actingAs($this->host)->test(ShowRoom::class, ['room' => $this->room])->call('revokeAllAccess');

        foreach (['cohost', 'other', 'guest'] as $who) {
            $this->assertNotNull($this->member($who)->left_at, $who);
        }
        $this->assertNull($this->hostMember->fresh()->left_at);
    }

    public function test_a_demoted_co_host_loses_the_extra_controls(): void
    {
        $this->as('cohost')->call('skip')->assertSet('controlErrorCode', '');

        $this->people['cohost']['member']->update(['role' => 'guest']);

        $this->as('cohost')->call('skip')->assertSet('controlErrorCode', 'ROOM-NO-PERMISSION');
    }

    public function test_the_guests_tab_shows_co_host_controls_to_the_right_people(): void
    {
        // Pending join requests and Remove for a co-host; Make co-host only for the host.
        $this->as('cohost')->call('setTab', 'guests')
            ->assertSee('Join requests')->assertSee('Pending')->assertSee('Co-host')
            ->assertDontSee('Make co-host')->assertDontSee('Guest permissions');

        $this->as('guest')->call('setTab', 'guests')->assertDontSee('Join requests')->assertDontSee('Make co-host');

        Livewire::actingAs($this->host)->test(ShowRoom::class, ['room' => $this->room])->call('setTab', 'guests')
            ->assertSee('Make co-host')->assertSee('Remove co-host');
    }

    public function test_the_co_hosts_menu_has_a_guests_link_but_a_guests_does_not(): void
    {
        $cohost = $this->withCookie(RoomMembership::cookieName($this->room), $this->people['cohost']['token'])
            ->get(route('rooms.show', $this->room))->assertOk()->getContent();
        $this->assertStringContainsString('tab=guests', $cohost);

        $guest = $this->withCookie(RoomMembership::cookieName($this->room), $this->people['guest']['token'])
            ->get(route('rooms.show', $this->room))->assertOk()->getContent();
        $this->assertStringNotContainsString('tab=guests', $guest);
    }

    public function test_co_hosts_are_listed_after_the_host_and_before_guests(): void
    {
        $names = $this->as('guest')->instance()->members->pluck('role')->all();

        $this->assertSame(['host', 'cohost', 'cohost', 'guest'], $names);
    }
}
