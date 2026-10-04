<?php

namespace Tests\Feature;

use App\Http\Middleware\ShowRevokedSessionNotice;
use App\Livewire\Actions\Logout;
use App\Models\Room;
use App\Models\User;
use App\Services\Auth\QrLogin;
use App\Services\Auth\UserSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** One test per problem found in the review of the logout, device and QR login work. */
class ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    private function row(User $user, string $id, int $idleMinutes = 1): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x',
            'payload' => '', 'last_activity' => now()->subMinutes($idleMinutes)->getTimestamp(),
        ]);
    }

    public function test_a_busy_lock_gives_a_retry_result_instead_of_a_server_error(): void
    {
        $user = User::factory()->create();
        $qr = new QrLogin(lockWaitSeconds: 0);
        $request = $qr->start('computer-session-id-0000000000000000001', 'ua');

        $held = Cache::lock('qr-login-lock:'.$request['token'], 10);
        $this->assertTrue($held->get());

        $this->assertSame(QrLogin::BUSY, $qr->approve($user, $request['token'], 'phone-session-id-000000000000000000001', $request['code']));
        $this->assertNull($qr->claim($request['token'], 'computer-session-id-0000000000000000001'));

        $held->release();
        $this->assertSame(QrLogin::APPROVED, $qr->approve($user, $request['token'], 'phone-session-id-000000000000000000001', $request['code']), 'and it works once the lock is free');
    }

    public function test_a_visitor_who_is_not_logged_in_cannot_deny_a_request(): void
    {
        $qr = app(QrLogin::class);
        $request = $qr->start('computer-session-id-0000000000000000001', 'ua');

        Volt::test('pages.auth.qr-approve', ['token' => $request['token']])->call('deny');

        $this->assertSame('pending', $qr->find($request['token'])['status']);
    }

    public function test_requesting_codes_is_limited_per_session_and_the_extra_ones_create_nothing(): void
    {
        RateLimiter::clear('qr-start:session:'.Session::getId());
        $component = Volt::test('auth.qr-login-panel');

        foreach (range(1, 10) as $i) {
            $component->call('start')->assertSet('state', 'pending');
        }

        $lastToken = $component->get('token');
        $component->call('start')->assertSet('state', 'limited')->assertSee('Too many codes');

        $this->assertSame($lastToken, $component->get('token'), 'no eleventh request was created');
    }

    public function test_the_qr_code_is_drawn_once_per_request_not_on_every_poll(): void
    {
        $component = Volt::test('auth.qr-login-panel')->call('start');
        $svg = $component->get('svg');

        $this->assertStringContainsString('<svg', $svg);

        $component->call('check')->call('check');

        $this->assertSame($svg, $component->get('svg'));
    }

    public function test_an_account_deleted_before_the_claim_does_not_log_anyone_in(): void
    {
        $user = User::factory()->create();
        $component = Volt::test('auth.qr-login-panel')->call('start');
        app(QrLogin::class)->approve($user, $component->get('token'), 'phone-session-id-000000000000000000001', $component->get('code'));
        $user->delete();

        $component->call('check')->assertSet('state', 'expired')->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_the_number_of_devices_signed_out_counts_live_ones_only(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->row($user, 'live-device-0000000000000000000000000000001');
        $this->row($user, 'stale-one-0000000000000000000000000000001', idleMinutes: (int) config('session.lifetime') + 60);
        $this->row($user, 'stale-two-0000000000000000000000000000001', idleMinutes: (int) config('session.lifetime') + 90);

        $count = app(Logout::class)->everywhere();

        $this->assertSame(2, $count, 'this device and the one live device, not the two that had already expired');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count(), 'the stale rows are still removed');
        $this->assertSame('Logged out on all 2 devices.', session('status'));
    }

    public function test_the_revoke_note_is_looked_up_once_per_session_not_on_every_request(): void
    {
        $user = User::factory()->create();
        $id = Str::random(40);
        $middleware = app(ShowRevokedSessionNotice::class);

        $store = app('session')->driver();
        $store->setId($id);
        $request = Request::create('/x', 'GET');
        $request->setLaravelSession($store);

        $middleware->handle($request, fn () => response('ok'));
        $this->assertTrue($store->has('_revocation_checked'), 'a clean check leaves a marker');

        // A note appears later for a session that still has its marker: not looked up again.
        $this->row($user, $id);
        app(UserSessions::class)->revoke($user, $id, 'device');
        $store->forget('status');
        $middleware->handle($request, fn () => response('ok'));
        $this->assertNull($store->get('status'));

        // Revocation deletes the session row, and with it the marker: the next request finds the note.
        $store->flush();
        $store->setId($id);
        $middleware->handle($request, fn () => response('ok'));
        $this->assertNotNull($store->get('status'));
    }

    public function test_without_database_sessions_device_tracking_says_so_instead_of_pretending(): void
    {
        config(['session.driver' => 'file']);
        $user = User::factory()->create();
        $this->row($user, 'would-count-if-supported-000000000000001');

        $sessions = app(UserSessions::class);

        $this->assertFalse($sessions->supported());
        $this->assertCount(0, $sessions->live($user));
    }

    public function test_both_menus_share_one_log_out_everywhere_implementation(): void
    {
        $nav = file_get_contents(resource_path('views/livewire/layout/navigation.blade.php'));
        $devices = file_get_contents(resource_path('views/livewire/profile/signed-in-devices.blade.php'));

        foreach ([$nav, $devices] as $component) {
            $this->assertStringContainsString('use LogsOutEverywhere;', $component);
            $this->assertStringNotContainsString('public function logoutEverywhere', $component, 'it lives in the trait only');
        }
    }

    public function test_the_home_page_shows_the_logout_notice_and_the_login_page_the_signed_out_reason(): void
    {
        $this->withSession(['status' => 'Logged out on all 3 devices and closed your room.'])->get('/')->assertSee('Logged out on all 3 devices and closed your room.');
        $this->withSession(['status' => 'You were signed out on this device from another one of your devices.'])->get('/login')->assertSee('signed out on this device');
    }
}
