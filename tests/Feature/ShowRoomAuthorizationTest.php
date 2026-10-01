<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\ShowRoom;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use App\Services\RoomMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShowRoomAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Room, 1: RoomMember, 2: string} room, host member, guest cookie token */
    private function roomWithApprovedGuest(): array
    {
        $host = User::factory()->create();
        $room = Room::create([
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC',
            'host_id' => $host->id,
            'playback_provider_id' => $host->id,
        ])->refresh();

        $membership = app(RoomMembership::class);
        $hostMember = $membership->joinAsHost($room);
        ['guest_token' => $token] = $membership->joinAsGuest($room, 'Mallory');
        RoomMember::where('guest_token', $token)->update(['approved_at' => now()]);

        return [$room, $hostMember, $token];
    }

    public function test_guest_cannot_become_host_by_overwriting_member_id(): void
    {
        [$room, $hostMember, $token] = $this->roomWithApprovedGuest();

        $component = Livewire::withCookie(RoomMembership::cookieName($room), $token)
            ->test(ShowRoom::class, ['room' => $room]);

        $this->assertFalse($component->get('isHost'));

        $this->expectExceptionMessage('Cannot update locked property');
        $component->set('memberId', $hostMember->id);
    }

    public function test_confirm_modal_only_dispatches_allowlisted_actions(): void
    {
        [$room, , $token] = $this->roomWithApprovedGuest();

        Livewire::withCookie(RoomMembership::cookieName($room), $token)
            ->test(ShowRoom::class, ['room' => $room])
            ->call('requestConfirm', 'logActivity', ['played', 'PWNED by guest'])
            ->call('confirmYes');

        $this->assertDatabaseMissing('activity_events', ['message' => 'PWNED by guest']);
    }

    public function test_host_cannot_point_playback_at_an_arbitrary_users_spotify_account(): void
    {
        [$room, $hostMember] = $this->roomWithApprovedGuest();
        $hostUser = $room->host;
        $stranger = User::factory()->create();

        $component = Livewire::actingAs($hostUser)->test(ShowRoom::class, ['room' => $room]);
        $component->call('switchProvider', $stranger->id);

        $this->assertSame($hostUser->id, $room->fresh()->playback_provider_id);
    }

    public function test_search_results_cannot_be_written_from_the_client(): void
    {
        [$room, , $token] = $this->roomWithApprovedGuest();

        $component = Livewire::withCookie(RoomMembership::cookieName($room), $token)
            ->test(ShowRoom::class, ['room' => $room]);

        $this->expectExceptionMessage('Cannot update locked property');
        $component->set('searchResults', [['id' => 'x', 'name' => 'forged']]);
    }

    public function test_pending_guest_cannot_spend_the_hosts_spotify_search_quota(): void
    {
        $host = User::factory()->create();
        \App\Models\SpotifyAccount::create([
            'user_id' => $host->id,
            'client_id' => 'id',
            'client_secret' => 'secret',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
        ]);
        $room = Room::create([
            'invite_code' => 'AAAAAA-BBBBBB-PPPPPP',
            'host_id' => $host->id,
            'playback_provider_id' => $host->id,
            'is_private' => true,
        ])->refresh();
        $membership = app(RoomMembership::class);
        $membership->joinAsHost($room);
        ['guest_token' => $token] = $membership->joinAsGuest($room, 'Pending');

        \Illuminate\Support\Facades\Http::fake(['api.spotify.com/*' => \Illuminate\Support\Facades\Http::response(['tracks' => ['items' => []]])]);

        Livewire::withCookie(RoomMembership::cookieName($room), $token)
            ->test(ShowRoom::class, ['room' => $room])
            ->set('search', 'anything');

        \Illuminate\Support\Facades\Http::assertNothingSent();

        // Control: once approved, the same search does reach Spotify.
        RoomMember::where('guest_token', $token)->update(['approved_at' => now()]);

        Livewire::withCookie(RoomMembership::cookieName($room), $token)
            ->test(ShowRoom::class, ['room' => $room])
            ->set('search', 'anything');

        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/search'));
    }
}
