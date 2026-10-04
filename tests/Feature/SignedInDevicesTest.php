<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\UserSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SignedInDevicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Device tracking reads the database session table (production's driver); the test store itself stays in memory.
        config(['session.driver' => 'database']);
    }

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    private const WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    private function row(User $user, string $id, string $agent, int $idleMinutes = 3): string
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => '203.0.113.9', 'user_agent' => $agent,
            'payload' => '', 'last_activity' => now()->subMinutes($idleMinutes)->getTimestamp(),
        ]);

        return $id;
    }

    public function test_it_lists_this_accounts_live_devices_with_browser_system_and_last_active(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->row($user, session()->getId(), self::IPHONE, 0);
        $this->row($user, 'laptop-0000000000000000000000000000000001', self::WINDOWS, 12);
        $this->row($user, 'expired-0000000000000000000000000000000001', self::WINDOWS, (int) config('session.lifetime') + 30);
        $this->row(User::factory()->create(), 'strangers-00000000000000000000000000000001', self::WINDOWS);

        $html = Volt::test('profile.signed-in-devices')->html();

        $this->assertStringContainsString('Safari on iPhone', $html);
        $this->assertStringContainsString('This device', $html);
        $this->assertStringContainsString('Chrome on Windows', $html);
        $this->assertStringContainsString('Last active 12 minutes ago', $html);
        $this->assertSame(2, substr_count($html, 'wire:key="device-'), "this device and the laptop only: an expired login and another account's login are not listed");
        $this->assertStringNotContainsString('203.0.113.9', $html, 'IP addresses are not shown');
    }

    public function test_the_current_device_is_listed_first_and_has_no_log_out_button(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->row($user, 'laptop-0000000000000000000000000000000001', self::WINDOWS, 1);
        $this->row($user, session()->getId(), self::IPHONE, 0);

        $devices = app(UserSessions::class)->devices($user, session()->getId());

        $this->assertTrue($devices[0]['current']);
        $this->assertFalse($devices[1]['current']);

        $html = Volt::test('profile.signed-in-devices')->html();
        $this->assertSame(1, substr_count($html, 'Log out this device'), 'only the other device can be logged out from here');
    }

    public function test_logging_out_another_device_removes_it_and_leaves_it_a_message(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $other = $this->row($user, 'laptop-0000000000000000000000000000000001', self::WINDOWS);

        Volt::test('profile.signed-in-devices')->call('revoke', $other);

        $this->assertSame(0, DB::table('sessions')->where('id', $other)->count());
        $this->assertSame('You were signed out on this device from another one of your devices.', UserSessions::noteMessage(app(UserSessions::class)->note($other)));
    }

    public function test_you_cannot_log_out_your_own_device_from_the_list(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $mine = $this->row($user, session()->getId(), self::IPHONE, 0);

        Volt::test('profile.signed-in-devices')->call('revoke', $mine);

        $this->assertSame(1, DB::table('sessions')->where('id', $mine)->count());
    }

    public function test_you_cannot_log_out_another_accounts_device(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $theirs = $this->row(User::factory()->create(), 'theirs-000000000000000000000000000000001', self::WINDOWS);

        Volt::test('profile.signed-in-devices')->call('revoke', $theirs);

        $this->assertSame(1, DB::table('sessions')->where('id', $theirs)->count());
        $this->assertNull(app(UserSessions::class)->note($theirs));
    }

    public function test_a_login_made_with_a_qr_code_says_so(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $id = $this->row($user, 'laptop-0000000000000000000000000000000001', self::WINDOWS);
        app(UserSessions::class)->markVia($id, 'qr');

        $this->assertStringContainsString('Logged in with a QR code', Volt::test('profile.signed-in-devices')->html());
    }

    public function test_the_page_shows_the_list_and_the_confirmation_counts_devices(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->row($user, session()->getId(), self::IPHONE, 0);
        $this->row($user, 'laptop-0000000000000000000000000000000001', self::WINDOWS);

        // Counted before the page request below, which (with the database driver) is itself a session.
        Volt::test('profile.signed-in-devices')->call('prepareLogoutEverywhere')->assertSet('deviceCount', 2);

        $this->get(route('profile'))->assertOk()->assertSee('Signed-in devices')->assertSee('Log out on all devices');
    }

    public function test_logging_out_everywhere_from_the_profile_page_signs_the_account_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->row($user, 'laptop-0000000000000000000000000000000001', self::WINDOWS);

        Volt::test('profile.signed-in-devices')->call('logoutEverywhere');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertGuest();
    }
}
