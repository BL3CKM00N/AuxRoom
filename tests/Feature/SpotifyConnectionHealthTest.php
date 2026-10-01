<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\PartyScreen;
use App\Livewire\Dashboard\ShowRoom;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\RoomMembership;
use App\Services\Spotify\SpotifyRequestFailed;
use App\Services\Spotify\SpotifyTokenManager;
use App\Services\Spotify\SpotifyWebApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SpotifyConnectionHealthTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $overrides = []): SpotifyAccount
    {
        return SpotifyAccount::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'client_id' => 'id',
            'client_secret' => 'secret',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->subMinute(),
        ], $overrides));
    }

    private function refreshes(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'accounts.spotify.com/api/token'))->count();
    }

    private function apiCalls(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.spotify.com'))->count();
    }

    public function test_a_permanent_rejection_flags_the_account_and_stops_further_refresh_attempts(): void
    {
        Http::fake(['accounts.spotify.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
        $account = $this->account();
        $manager = app(SpotifyTokenManager::class);

        $manager->ensureFreshToken($account);
        $this->assertTrue($account->fresh()->needsReconnect());
        $this->assertSame(1, $this->refreshes());

        foreach (range(1, 20) as $_) {
            $manager->ensureFreshToken($account->fresh());
        }
        $this->assertSame(1, $this->refreshes(), 'no retry storm while flagged');
    }

    public function test_a_flagged_account_heals_itself_when_a_later_retry_succeeds(): void
    {
        Http::fakeSequence('accounts.spotify.com/*')
            ->push(['error' => 'invalid_grant'], 400)
            ->push(['access_token' => 'new', 'expires_in' => 3600], 200);
        $account = $this->account();
        $manager = app(SpotifyTokenManager::class);

        $manager->ensureFreshToken($account);
        $this->assertTrue($account->fresh()->needsReconnect());

        $this->travel(6)->minutes();
        $manager->ensureFreshToken($account->fresh());

        $this->assertSame(2, $this->refreshes(), 'exactly one retry after the window');
        $this->assertFalse($account->fresh()->needsReconnect());
        $this->assertSame('new', $account->fresh()->access_token);
    }

    public function test_a_rotated_client_secret_also_counts_as_permanent(): void
    {
        Http::fake(['accounts.spotify.com/*' => Http::response(['error' => 'invalid_client'], 401)]);
        $account = $this->account();

        app(SpotifyTokenManager::class)->ensureFreshToken($account);

        $this->assertTrue($account->fresh()->needsReconnect());
    }

    public function test_transient_failures_never_flag_the_account_and_retry_freely(): void
    {
        foreach ([429, 500, 503] as $status) {
            Http::fake(['accounts.spotify.com/*' => Http::response(['error' => 'x'], $status)]);
            $account = $this->account();
            $manager = app(SpotifyTokenManager::class);

            $manager->ensureFreshToken($account);
            $manager->ensureFreshToken($account->fresh());

            $this->assertFalse($account->fresh()->needsReconnect(), "status $status");
            $account->delete();
        }

        Http::fake(fn () => throw new ConnectionException('timed out'));
        $account = $this->account();
        app(SpotifyTokenManager::class)->ensureFreshToken($account);
        $this->assertFalse($account->fresh()->needsReconnect(), 'a network error is not a rejection');
    }

    public function test_a_flagged_account_makes_no_spotify_calls_and_commands_fail_cleanly(): void
    {
        Http::fake();
        $client = new SpotifyWebApiClient($this->account(['token_expires_at' => now()->addHour(), 'needs_reconnect_at' => now()]));

        $this->assertSame([], $client->getQueue());
        $this->assertSame([], $client->getDevices());
        $this->assertSame([], $client->search('x'));
        $this->assertFalse($client->pause());
        $this->assertFalse($client->skipToNext());
        $this->assertSame(0, $this->apiCalls());

        $this->expectException(SpotifyRequestFailed::class);
        $client->getPlaybackState();
    }

    public function test_connection_errors_degrade_gracefully_instead_of_throwing(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));
        $client = new SpotifyWebApiClient($this->account(['token_expires_at' => now()->addHour()]));

        $this->assertSame([], $client->getQueue());
        $this->assertSame([], $client->getDevices());
        $this->assertSame([], $client->search('x'));
        $this->assertNull($client->getPlaylist('abc'));
        $this->assertFalse($client->pause());
        $this->assertFalse($client->addToPlaybackQueue('spotify:track:x'));

        $this->expectException(SpotifyRequestFailed::class);
        $client->getPlaybackState();
    }

    /** @return array{0: Room, 1: string, 2: SpotifyAccount} */
    private function roomWithFlaggedProvider(): array
    {
        $host = User::factory()->create();
        $account = SpotifyAccount::create([
            'user_id' => $host->id,
            'client_id' => 'id',
            'client_secret' => 'secret',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'needs_reconnect_at' => now(),
        ]);
        $room = Room::create([
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC',
            'host_id' => $host->id,
            'playback_provider_id' => $host->id,
            'is_private' => false,
        ])->refresh();
        $membership = app(RoomMembership::class);
        $membership->joinAsHost($room);
        ['guest_token' => $token] = $membership->joinAsGuest($room, 'Guest');
        RoomMember::where('guest_token', $token)->update(['can_play_pause' => true]);

        return [$room, $token, $account];
    }

    public function test_guests_are_told_the_real_reason_and_nothing_reaches_spotify(): void
    {
        [$room, $token] = $this->roomWithFlaggedProvider();
        Http::fake();

        Livewire::withCookie(RoomMembership::cookieName($room), $token)
            ->test(ShowRoom::class, ['room' => $room])
            ->assertSee('Spotify disconnected')
            ->call('play')
            ->assertSet('controlError', 'Spotify needs to be reconnected by the host.');

        $this->assertSame(0, $this->apiCalls());
    }

    public function test_the_host_is_pointed_at_room_settings_and_the_party_screen_explains_itself(): void
    {
        [$room, , $account] = $this->roomWithFlaggedProvider();
        Http::fake();

        Livewire::actingAs($room->host)
            ->test(ShowRoom::class, ['room' => $room])
            ->assertSee('Reconnect')
            ->call('play')
            ->assertSet('controlError', 'Your Spotify connection expired. Reconnect it in Room Settings.');

        Livewire::test(PartyScreen::class, ['room' => $room])
            ->assertSee('Spotify disconnected')
            ->assertSee('Ask the host to reconnect Spotify');
    }

    public function test_a_flagged_account_cannot_be_switched_to_as_the_playback_provider(): void
    {
        [$room, , $account] = $this->roomWithFlaggedProvider();

        $component = Livewire::actingAs($room->host)->test(ShowRoom::class, ['room' => $room]);

        $this->assertTrue($component->get('eligibleProviders')->isEmpty());
    }
}
