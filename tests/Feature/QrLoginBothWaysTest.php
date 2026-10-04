<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\QrLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** The direction where a logged-in computer shows the code and a logged-out phone scans it. */
class QrLoginBothWaysTest extends TestCase
{
    use RefreshDatabase;

    private const COMPUTER = 'computer-session-id-0000000000000000001';

    private const PHONE = 'phone-session-id-00000000000000000000001';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    private QrLogin $qr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->qr = app(QrLogin::class);
    }

    /** @return array{0: User, 1: string, 2: string} the offering user, the token and the number the phone got */
    private function offeredAndClaimed(): array
    {
        $user = User::factory()->create();
        $token = $this->qr->offer($user, self::COMPUTER)['token'];
        $result = $this->qr->claimOffer($token, self::PHONE, self::IPHONE);

        return [$user, $token, substr($result, strlen(QrLogin::CLAIMED_PREFIX))];
    }

    // ---- the service

    public function test_an_offer_is_a_long_random_token_waiting_for_someone_to_scan_it(): void
    {
        $user = User::factory()->create();
        $offer = $this->qr->offer($user, self::COMPUTER);
        $record = $this->qr->find($offer['token']);

        $this->assertSame(40, strlen($offer['token']));
        $this->assertSame(['offer', 'open', null], [$record['direction'], $record['status'], $record['code']], 'no number until someone scans it');
        $this->assertNotSame($offer['token'], $this->qr->offer($user, self::COMPUTER)['token']);
    }

    public function test_scanning_gives_the_phone_a_number_and_ties_the_login_to_the_phone(): void
    {
        $user = User::factory()->create();
        $token = $this->qr->offer($user, self::COMPUTER)['token'];

        $result = $this->qr->claimOffer($token, self::PHONE, self::IPHONE);

        $this->assertMatchesRegularExpression('/^claimed:[1-9][0-9]$/', $result);
        $record = $this->qr->find($token);
        $this->assertSame(['claimed', self::PHONE], [$record['status'], $record['session']]);
    }

    public function test_a_code_can_be_scanned_once_and_not_by_the_screen_showing_it(): void
    {
        $user = User::factory()->create();
        $token = $this->qr->offer($user, self::COMPUTER)['token'];

        $this->assertSame(QrLogin::SAME_DEVICE, $this->qr->claimOffer($token, self::COMPUTER, 'ua'), 'scanning your own code is refused');
        $this->assertStringStartsWith('claimed:', $this->qr->claimOffer($token, self::PHONE, 'ua'));
        $this->assertSame(QrLogin::NOT_PENDING, $this->qr->claimOffer($token, 'another-phone-session-0000000000000001', 'ua'), 'a second scanner is turned away');
        $this->assertSame(QrLogin::EXPIRED, $this->qr->claimOffer(str_repeat('z', 40), self::PHONE, 'ua'));
    }

    public function test_the_two_directions_cannot_be_mixed_up(): void
    {
        $user = User::factory()->create();
        $ask = $this->qr->start(self::COMPUTER, 'ua');
        $offer = $this->qr->offer($user, self::COMPUTER)['token'];

        $this->assertSame(QrLogin::NOT_PENDING, $this->qr->claimOffer($ask['token'], self::PHONE, 'ua'), 'a code that asks to be approved cannot be claimed');
        $this->assertSame(QrLogin::NOT_PENDING, $this->qr->approve($user, $offer, self::PHONE, '10'), 'an offer is not approved the other way round');
    }

    public function test_the_offering_device_approves_with_the_number_and_only_the_phone_can_then_collect_the_login(): void
    {
        [$user, $token, $number] = $this->offeredAndClaimed();

        $this->assertSame(QrLogin::APPROVED, $this->qr->approveClaim($user, $token, self::COMPUTER, $number));

        $this->assertNull($this->qr->claim($token, self::COMPUTER), 'not the device that approved');
        $this->assertNull($this->qr->claim($token, 'a-stranger-session-id-000000000000001'), 'not a copy of the token');
        $this->assertSame($user->id, $this->qr->claim($token, self::PHONE));
        $this->assertNull($this->qr->claim($token, self::PHONE), 'a login code works once');
    }

    public function test_only_the_offering_browser_of_the_offering_account_can_approve(): void
    {
        [$user, $token, $number] = $this->offeredAndClaimed();

        $this->assertSame(QrLogin::NOT_PENDING, $this->qr->approveClaim($user, $token, 'some-other-session-id-0000000000000001', $number), 'another browser');
        $this->assertSame(QrLogin::NOT_PENDING, $this->qr->approveClaim(User::factory()->create(), $token, self::COMPUTER, $number), 'another account');
        $this->assertSame('claimed', $this->qr->find($token)['status']);
    }

    public function test_the_wrong_number_does_not_approve_and_three_cancel_it(): void
    {
        [$user, $token, $number] = $this->offeredAndClaimed();
        $wrong = $number === '99' ? '98' : '99';

        $this->assertSame(QrLogin::WRONG_NUMBER, $this->qr->approveClaim($user, $token, self::COMPUTER, $wrong));
        $this->assertSame(QrLogin::WRONG_NUMBER, $this->qr->approveClaim($user, $token, self::COMPUTER, $wrong));
        $this->assertSame(QrLogin::TOO_MANY, $this->qr->approveClaim($user, $token, self::COMPUTER, $wrong));

        $this->assertSame('denied', $this->qr->find($token)['status']);
        $this->assertNull($this->qr->claim($token, self::PHONE));
    }

    public function test_an_unscanned_or_expired_offer_cannot_be_approved(): void
    {
        $user = User::factory()->create();
        $token = $this->qr->offer($user, self::COMPUTER)['token'];

        $this->assertSame(QrLogin::NOT_PENDING, $this->qr->approveClaim($user, $token, self::COMPUTER, '42'), 'nobody has scanned it yet');

        Cache::forget('qr-login:'.$token);
        $this->assertSame(QrLogin::EXPIRED, $this->qr->approveClaim($user, $token, self::COMPUTER, '42'));
    }

    // ---- the logged-out phone's login page

    public function test_the_phone_scans_gets_a_number_and_is_logged_in_when_the_computer_approves(): void
    {
        $user = User::factory()->create();
        RateLimiter::clear('qr-start:session:'.Session::getId());
        $token = $this->qr->offer($user, self::COMPUTER)['token'];

        $panel = Volt::test('auth.qr-login-panel')->call('scanned', $token);

        $panel->assertSet('state', 'claimed')->assertSet('role', 'scan')->assertSee('Waiting for your computer');
        $number = $panel->get('code');
        $panel->assertSee($number);

        $this->assertSame(QrLogin::APPROVED, $this->qr->approveClaim($user, $token, self::COMPUTER, $number));

        $panel->call('check')->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('qr', Cache::get('session-via:'.session()->getId()));
    }

    public function test_the_phone_is_not_logged_in_before_the_computer_approves(): void
    {
        $user = User::factory()->create();
        $token = $this->qr->offer($user, self::COMPUTER)['token'];

        $panel = Volt::test('auth.qr-login-panel')->call('scanned', $token)->call('check')->call('check');

        $panel->assertSet('state', 'claimed')->assertNoRedirect();
        $this->assertGuest();
    }

    public function test_a_scanned_code_that_is_not_a_login_code_or_has_expired_says_so(): void
    {
        Volt::test('auth.qr-login-panel')->call('scanned', 'not-a-token')
            ->assertSet('state', 'idle')->assertSee("isn't an AuxRoom login code");

        Volt::test('auth.qr-login-panel')->call('scanned', str_repeat('q', 40))
            ->assertSet('state', 'idle')->assertSee('expired or was already used');
    }

    public function test_a_denied_claim_tells_the_phone(): void
    {
        $user = User::factory()->create();
        $token = $this->qr->offer($user, self::COMPUTER)['token'];
        $panel = Volt::test('auth.qr-login-panel')->call('scanned', $token);

        $this->qr->deny($token);

        $panel->call('check')->assertSet('state', 'denied')->assertSee('AUTH-QR-DENIED');
        $this->assertGuest();
    }

    public function test_scanning_is_rate_limited_like_asking(): void
    {
        RateLimiter::clear('qr-start:session:'.Session::getId());
        $panel = Volt::test('auth.qr-login-panel');

        foreach (range(1, 10) as $i) {
            $panel->call('scanned', str_repeat('q', 40));
        }

        $panel->call('scanned', str_repeat('q', 40))->assertSet('state', 'limited');
    }

    // ---- the logged-in computer's side

    public function test_the_computer_shows_a_code_then_the_phones_request_and_approves_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        RateLimiter::clear('qr-start:user:'.$user->id);

        $panel = Volt::test('account.link-device-panel')->call('showCode');
        $panel->assertSet('state', 'open')->assertSeeHtml('<svg');
        $token = $panel->get('token');
        $this->assertSame('offer', $this->qr->find($token)['direction']);

        // The phone scans it.
        $number = substr($this->qr->claimOffer($token, self::PHONE, self::IPHONE), strlen(QrLogin::CLAIMED_PREFIX));

        $panel->call('check')->assertSet('state', 'claimed')->assertSee('Safari on iPhone')->assertSee('wants to log in as you');

        $panel->set('typed', $number)->call('approve')->assertSet('state', 'approved')->assertSee('Approved');
        $this->assertSame('approved', $this->qr->find($token)['status']);
    }

    public function test_the_computer_shows_a_clear_message_for_a_wrong_number(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $panel = Volt::test('account.link-device-panel')->call('showCode');
        $token = $panel->get('token');
        $number = substr($this->qr->claimOffer($token, self::PHONE, self::IPHONE), strlen(QrLogin::CLAIMED_PREFIX));
        $panel->call('check');

        $panel->set('typed', $number === '99' ? '98' : '99')->call('approve')
            ->assertSee("That number doesn't match")->assertSee('[AUTH-QR-CODE]')->assertSet('state', 'claimed');
    }

    public function test_denying_on_the_computer_or_stopping_the_code_turns_the_phone_away(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $panel = Volt::test('account.link-device-panel')->call('showCode');
        $token = $panel->get('token');
        $this->qr->claimOffer($token, self::PHONE, self::IPHONE);
        $panel->call('check')->call('deny')->assertSet('state', 'denied');
        $this->assertSame('denied', $this->qr->find($token)['status']);

        $second = Volt::test('account.link-device-panel')->call('showCode');
        $token2 = $second->get('token');
        $second->call('stop')->assertSet('state', 'idle');
        $this->assertSame('denied', $this->qr->find($token2)['status'], 'a code that is no longer shown cannot be scanned');
    }

    public function test_showing_codes_is_rate_limited_per_account(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        RateLimiter::clear('qr-start:user:'.$user->id);
        $panel = Volt::test('account.link-device-panel');

        foreach (range(1, 10) as $i) {
            $panel->call('showCode');
        }

        $panel->call('showCode')->assertSet('state', 'limited')->assertSee('AUTH-QR-LIMIT');
    }

    // ---- scanning with a camera app instead: the code lands in a browser

    public function test_a_logged_out_browser_that_opens_an_offered_code_can_claim_it_and_is_logged_in_on_approval(): void
    {
        $user = User::factory()->create();
        $token = $this->qr->offer($user, self::COMPUTER)['token'];

        $page = Volt::test('pages.auth.qr-approve', ['token' => $token])
            ->assertSee('offering to log in this browser')->assertSee('Log in this browser')
            ->assertDontSee($user->name, 'the account name is not shown to whoever scans the code');

        $page->call('claim');
        $number = $page->get('claimedCode');
        $this->assertMatchesRegularExpression('/^[1-9][0-9]$/', $number);
        $page->assertSee($number);

        $this->qr->approveClaim($user, $token, self::COMPUTER, $number);

        $page->call('check')->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_logged_in_browser_that_opens_an_offered_code_is_told_it_is_already_logged_in(): void
    {
        $user = User::factory()->create();
        $token = $this->qr->offer($user, self::COMPUTER)['token'];

        $this->actingAs(User::factory()->create());
        Volt::test('pages.auth.qr-approve', ['token' => $token])->assertSee("already logged in on this device")->assertDontSee('Approve');
    }

    // ---- defaults and the scanner

    public function test_the_login_popup_offers_both_sides_with_a_switch_and_defaults_by_device(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString("matchMedia('(pointer: coarse)')", $html, 'phones start with the camera, computers with the code');
        $this->assertStringContainsString("qrScanner('', null, 'token')", $html);
        $this->assertStringContainsString('Show a code instead', $html);
        $this->assertStringContainsString('Scan the code on your computer', $html);
    }

    public function test_the_profile_page_for_a_logged_in_device_does_the_same(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('account.link-device'))->assertOk()->getContent();

        $this->assertStringContainsString("matchMedia('(pointer: coarse)')", $html);
        $this->assertStringContainsString("qrScanner('', null, 'login')", $html, 'a phone scans the code a logged-out computer shows');
        $this->assertStringContainsString('Scan a code instead', $html);
        $this->assertStringContainsString('Show a code instead', $html);
    }

    public function test_the_scanner_can_hand_a_scanned_token_to_the_page(): void
    {
        $js = file_get_contents(resource_path('js/alpine/qr-scanner.js'));

        $this->assertStringContainsString("mode === 'token'", $js);
        $this->assertStringContainsString("'qr-token-scanned'", $js);
    }
}
