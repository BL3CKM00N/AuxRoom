<?php

namespace Tests\Feature;

use App\Http\Middleware\ShowRevokedSessionNotice;
use App\Livewire\Actions\Logout;
use App\Models\ActivityEvent;
use App\Models\Room;
use App\Models\User;
use App\Services\Auth\UserSessions;
use App\Services\RoomMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

class LogoutRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Device tracking reads the database session table (production's driver); the test store itself stays in memory.
        config(['session.driver' => 'database']);
    }

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    private function host(): array
    {
        $host = User::factory()->create();
        $room = Room::create(['invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $host->id, 'playback_provider_id' => $host->id])->refresh();
        app(RoomMembership::class)->joinAsHost($room);

        return [$host, $room];
    }

    /** A session row as the database session driver stores it. */
    private function loginRow(User $user, string $id, ?int $idleMinutes = 1, string $agent = self::IPHONE): string
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => $agent,
            'payload' => '', 'last_activity' => now()->subMinutes($idleMinutes)->getTimestamp(),
        ]);

        return $id;
    }

    private function logOut(User $user): void
    {
        $this->actingAs($user);
        app(Logout::class)();
    }

    public function test_the_last_device_logging_out_closes_the_room(): void
    {
        [$host, $room] = $this->host();

        $this->logOut($host);

        $this->assertNotNull($room->refresh()->closed_at);
        $this->assertTrue(ActivityEvent::where('room_id', $room->id)->where('message', 'like', '%host logged out%')->exists());
        $this->assertGuest();
    }

    public function test_with_another_live_device_the_room_stays_open_and_you_are_told(): void
    {
        [$host, $room] = $this->host();
        $this->loginRow($host, 'other-device-session-0000000000000000000001');

        $this->logOut($host);

        $this->assertNull($room->refresh()->closed_at);
        $this->assertSame("Logged out here. Your room stays open because you're still signed in on another device.", session('status'));
        $this->assertGuest();
    }

    public function test_a_device_idle_past_the_session_lifetime_does_not_count(): void
    {
        [$host, $room] = $this->host();
        $this->loginRow($host, 'stale-device-session-000000000000000000001', idleMinutes: (int) config('session.lifetime') + 5);

        $this->logOut($host);

        $this->assertNotNull($room->refresh()->closed_at, 'that login has already expired');
    }

    public function test_someone_elses_live_session_never_counts(): void
    {
        [$host, $room] = $this->host();
        $this->loginRow(User::factory()->create(), 'strangers-session-0000000000000000000000001');

        $this->logOut($host);

        $this->assertNotNull($room->refresh()->closed_at);
    }

    public function test_logging_out_without_a_room_just_logs_out(): void
    {
        $user = User::factory()->create();
        $this->loginRow($user, 'other-device-session-0000000000000000000001');

        $this->logOut($user);

        $this->assertGuest();
        $this->assertNull(session('status'), 'there was no room to mention');
    }

    public function test_logout_everywhere_signs_every_device_out_closes_the_room_and_ends_remember_me(): void
    {
        [$host, $room] = $this->host();
        $host->forceFill(['remember_token' => 'old-token'])->save();
        $this->loginRow($host, 'phone-session-000000000000000000000000000001');
        $this->loginRow($host, 'laptop-session-00000000000000000000000000001', agent: self::CHROME_WINDOWS);
        $this->loginRow($stranger = User::factory()->create(), 'strangers-session-0000000000000000000000001');

        $this->actingAs($host);
        $count = app(Logout::class)->everywhere();

        $this->assertSame(3, $count, 'two other devices plus this one');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $host->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $stranger->id)->count(), "another account's session is untouched");
        $this->assertNotSame('old-token', $host->refresh()->remember_token, 'Remember me cookies stop working');
        $this->assertNotNull($room->refresh()->closed_at);
        $this->assertTrue(ActivityEvent::where('room_id', $room->id)->where('message', 'like', '%all devices%')->exists());
        $this->assertSame('Logged out on all 3 devices and closed your room.', session('status'));
        $this->assertGuest();
    }

    public function test_logout_everywhere_without_a_room_says_only_that(): void
    {
        $user = User::factory()->create();
        $this->loginRow($user, 'phone-session-000000000000000000000000000001');

        $this->actingAs($user);
        app(Logout::class)->everywhere();

        $this->assertSame('Logged out on all 2 devices.', session('status'));
    }

    public function test_the_menu_buttons_run_the_same_rules(): void
    {
        [$host, $room] = $this->host();
        $this->loginRow($host, 'other-device-session-0000000000000000000001');

        Volt::actingAs($host)->test('layout.navigation')->call('logout');
        $this->assertNull($room->refresh()->closed_at, 'another device is still signed in');

        [$host2, $room2] = [User::factory()->create(), null];
        $room2 = Room::create(['invite_code' => 'DDDDDD-EEEEEE-FFFFFF', 'host_id' => $host2->id, 'playback_provider_id' => $host2->id]);
        $this->loginRow($host2, 'second-hosts-phone-0000000000000000000001');

        Volt::actingAs($host2)->test('layout.navigation')->call('logoutEverywhere');
        $this->assertNotNull($room2->refresh()->closed_at);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $host2->id)->count());
    }

    public function test_the_confirmation_counts_the_devices_before_it_opens(): void
    {
        $user = User::factory()->create();
        $this->loginRow($user, 'one-session-0000000000000000000000000000001');
        $this->loginRow($user, 'two-session-0000000000000000000000000000001');

        Volt::actingAs($user)->test('layout.navigation')->call('prepareLogoutEverywhere')->assertSet('deviceCount', 2);
    }

    public function test_deleting_the_account_ends_every_login_and_takes_the_room_with_it(): void
    {
        [$host, $room] = $this->host();
        $this->loginRow($host, 'other-device-session-0000000000000000000001');

        Volt::actingAs($host)->test('profile.delete-user-form')->set('password', 'password')->call('deleteUser');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $host->id)->count(), 'no device stays signed in');
        $this->assertNull(Room::find($room->id), 'the room goes with the account');
        $this->assertNull(User::find($host->id));
    }

    // ---- the message on a device that was signed out remotely

    private function passThrough(string $sessionId, bool $livewire): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create('/dashboard', 'GET');
        $store = app('session')->driver();
        $store->setId($sessionId);
        $request->setLaravelSession($store);

        if ($livewire) {
            $request->headers->set('X-Livewire', 'true');
        }

        return app(ShowRevokedSessionNotice::class)->handle($request, fn () => response('page'));
    }

    public function test_a_revoked_device_sees_why_on_its_next_page_load_once(): void
    {
        [$host] = $this->host();
        $id = $this->loginRow($host, Str::random(40));
        app(UserSessions::class)->revoke($host, $id, 'everywhere');

        $response = $this->passThrough($id, livewire: false);

        $this->assertSame('page', $response->getContent());
        $this->assertSame('You were signed out because you chose to log out everywhere on another device.', session('status'));

        Session::forget('status');
        $this->passThrough($id, livewire: false);
        $this->assertNull(session('status'), 'the note is shown once');
    }

    public function test_an_open_tab_refreshing_after_being_revoked_gets_a_401_and_keeps_the_note_for_the_page_load(): void
    {
        [$host] = $this->host();
        $id = $this->loginRow($host, Str::random(40));
        app(UserSessions::class)->revoke($host, $id, 'device');

        $this->assertSame(401, $this->passThrough($id, livewire: true)->getStatusCode());
        $this->assertNotNull(app(UserSessions::class)->note($id), 'still there for the login page');

        $this->passThrough($id, livewire: false);
        $this->assertSame('You were signed out on this device from another one of your devices.', session('status'));
    }

    public function test_requests_without_a_note_pass_straight_through(): void
    {
        $this->assertSame('page', $this->passThrough(Str::random(40), livewire: true)->getContent());
        $this->assertNull(session('status'));
    }
}
