<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\QrLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;
use Tests\TestCase;

class QrLoginTest extends TestCase
{
    use RefreshDatabase;

    private const COMPUTER_SESSION = 'computer-session-id-0000000000000000001';

    private const PHONE_SESSION = 'phone-session-id-00000000000000000000001';

    private QrLogin $qr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->qr = app(QrLogin::class);
    }

    private function ask(): array
    {
        return $this->qr->start(self::COMPUTER_SESSION, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0.0.0 Safari/537.36');
    }

    // ---- the service: the rules that make it safe

    public function test_a_request_has_a_long_random_token_and_a_two_digit_number(): void
    {
        $request = $this->ask();

        $this->assertSame(40, strlen($request['token']));
        $this->assertMatchesRegularExpression('/^[1-9][0-9]$/', $request['code']);
        $this->assertNotSame($request['token'], $this->ask()['token']);
    }

    public function test_the_right_number_approves_and_the_computer_can_then_claim_exactly_once(): void
    {
        $user = User::factory()->create();
        $request = $this->ask();

        $this->assertSame(QrLogin::APPROVED, $this->qr->approve($user, $request['token'], self::PHONE_SESSION, $request['code']));

        $this->assertSame($user->id, $this->qr->claim($request['token'], self::COMPUTER_SESSION));
        $this->assertNull($this->qr->claim($request['token'], self::COMPUTER_SESSION), 'a login code works once');
    }

    public function test_only_the_session_that_asked_can_claim_it(): void
    {
        $user = User::factory()->create();
        $request = $this->ask();
        $this->qr->approve($user, $request['token'], self::PHONE_SESSION, $request['code']);

        $this->assertNull($this->qr->claim($request['token'], 'some-other-browser-session-00000000000001'), 'a copied token is useless elsewhere');
        $this->assertSame($user->id, $this->qr->claim($request['token'], self::COMPUTER_SESSION), 'and the real one is unharmed');
    }

    public function test_nothing_can_be_claimed_before_it_is_approved(): void
    {
        $request = $this->ask();

        $this->assertNull($this->qr->claim($request['token'], self::COMPUTER_SESSION));
    }

    public function test_a_wrong_number_does_not_approve_and_three_cancel_the_request(): void
    {
        $user = User::factory()->create();
        $request = $this->ask();
        $wrong = (string) (((int) $request['code'] % 80) + 10 === (int) $request['code'] ? 99 : ((int) $request['code'] % 80) + 10);

        $this->assertSame(QrLogin::WRONG_NUMBER, $this->qr->approve($user, $request['token'], self::PHONE_SESSION, $wrong));
        $this->assertSame(QrLogin::WRONG_NUMBER, $this->qr->approve($user, $request['token'], self::PHONE_SESSION, $wrong));
        $this->assertSame(QrLogin::TOO_MANY, $this->qr->approve($user, $request['token'], self::PHONE_SESSION, $wrong));

        $this->assertSame('denied', $this->qr->find($request['token'])['status']);
        $this->assertSame(QrLogin::NOT_PENDING, $this->qr->approve($user, $request['token'], self::PHONE_SESSION, $request['code']), 'even the right number is too late now');
        $this->assertNull($this->qr->claim($request['token'], self::COMPUTER_SESSION));
    }

    public function test_the_asking_browser_cannot_approve_its_own_request(): void
    {
        $user = User::factory()->create();
        $request = $this->ask();

        $this->assertSame(QrLogin::SAME_DEVICE, $this->qr->approve($user, $request['token'], self::COMPUTER_SESSION, $request['code']));
        $this->assertSame('pending', $this->qr->find($request['token'])['status']);
    }

    public function test_an_unknown_or_expired_token_cannot_be_approved(): void
    {
        $user = User::factory()->create();

        $this->assertSame(QrLogin::EXPIRED, $this->qr->approve($user, str_repeat('a', 40), self::PHONE_SESSION, '42'));

        $request = $this->ask();
        Cache::forget('qr-login:'.$request['token']);
        $this->assertSame(QrLogin::EXPIRED, $this->qr->approve($user, $request['token'], self::PHONE_SESSION, $request['code']));
    }

    public function test_a_request_lives_two_minutes_and_wrong_guesses_do_not_extend_it(): void
    {
        $this->assertSame(120, QrLogin::TTL_SECONDS);

        $user = User::factory()->create();
        $request = $this->ask();
        $this->travel(100)->seconds();
        $this->qr->approve($user, $request['token'], self::PHONE_SESSION, '00');   // a wrong guess near the end
        $this->travel(30)->seconds();

        $this->assertNull($this->qr->find($request['token']), 'gone at two minutes, whatever happened in between');
    }

    public function test_denying_cancels_it(): void
    {
        $request = $this->ask();

        $this->qr->deny($request['token']);

        $this->assertSame('denied', $this->qr->find($request['token'])['status']);
    }

    // ---- the computer's side

    public function test_the_login_page_offers_it_but_creates_nothing_until_pressed(): void
    {
        $this->get('/login')->assertOk()->assertSee('Log in with QR');

        $this->assertNull(Cache::get('qr-login:anything'));
    }

    public function test_pressing_the_button_shows_a_qr_code_and_the_number(): void
    {
        $component = Volt::test('auth.qr-login-panel')->call('start');

        $component->assertSet('state', 'pending')->assertSeeHtml('<svg');
        $this->assertSame($component->get('code'), $this->qr->find($component->get('token'))['code']);
        $component->assertSee($component->get('code'));
        $this->assertSame(40, strlen($component->get('token')));
    }

    public function test_after_approval_the_computer_is_logged_in_and_marked_as_a_qr_login(): void
    {
        $user = User::factory()->create();
        $component = Volt::test('auth.qr-login-panel')->call('start');
        $token = $component->get('token');

        // The phone approves (any session other than the computer's own).
        $this->assertSame(QrLogin::APPROVED, $this->qr->approve($user, $token, self::PHONE_SESSION, $component->get('code')));

        $component->call('check')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('qr', Cache::get('session-via:'.session()->getId()), 'the new session is marked, for the devices list');
    }

    public function test_a_denied_request_tells_the_computer(): void
    {
        $component = Volt::test('auth.qr-login-panel')->call('start');
        $this->qr->deny($component->get('token'));

        $component->call('check')->assertSet('state', 'denied')->assertSee('AUTH-QR-DENIED');
        $this->assertGuest();
    }

    public function test_an_expired_request_asks_for_a_new_code(): void
    {
        $component = Volt::test('auth.qr-login-panel')->call('start');
        Cache::forget('qr-login:'.$component->get('token'));

        $component->call('check')->assertSet('state', 'expired')->assertSee('Show a new code');

        $component->call('start')->assertSet('state', 'pending');
    }

    public function test_cancelling_the_popup_drops_the_request(): void
    {
        $component = Volt::test('auth.qr-login-panel')->call('start');
        $token = $component->get('token');

        $component->call('cancel')->assertSet('state', 'idle')->assertSet('token', '');

        $this->assertSame('denied', $this->qr->find($token)['status'], 'it can no longer be approved');
    }

    public function test_the_button_sits_between_forgot_password_and_log_in_and_the_code_lives_in_a_popup(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $forgot = strpos($html, 'Forgot your password?');
        $qr = strpos($html, 'Log in with QR');
        preg_match('/<button[^>]*type="submit"[^>]*>\s*Log in\s*<\/button>/i', $html, $m, PREG_OFFSET_CAPTURE);

        $this->assertNotFalse($forgot);
        $this->assertNotFalse($qr);
        $this->assertNotEmpty($m, 'the Log in submit button is found');
        $this->assertLessThan($qr, $forgot, 'after "Forgot your password?"');
        $this->assertLessThan($m[0][1], $qr, 'before the Log in button');
        $this->assertStringContainsString("open-modal', 'qr-login'", $html);
        $this->assertStringContainsString("== 'qr-login'", $html, 'the popup listens for that name');
        $this->assertStringNotContainsString('Number to type on your phone', $html, 'no code is shown until the button is pressed');
    }

    // ---- the phone's side

    public function test_a_logged_out_visitor_who_opens_the_link_is_told_how_to_approve_it(): void
    {
        $request = $this->ask();

        $this->get(route('qr-login.show', $request['token']))->assertOk()
            ->assertSee('Log in another device')
            ->assertSee('already logged in to AuxRoom')
            ->assertDontSee('Approve');
    }

    public function test_a_logged_in_phone_sees_who_is_asking_and_approves_with_the_number(): void
    {
        $user = User::factory()->create();
        $request = $this->ask();

        $this->actingAs($user);
        Volt::test('pages.auth.qr-approve', ['token' => $request['token']])
            ->assertSee('Chrome on Windows')
            ->assertSee($user->name)
            ->set('typed', $request['code'])
            ->call('approve')
            ->assertSet('outcome', 'approved')
            ->assertSee('Approved');

        $this->assertSame('approved', $this->qr->find($request['token'])['status']);
    }

    public function test_the_phone_gets_a_clear_message_for_a_wrong_number_and_after_three_the_request_is_cancelled(): void
    {
        $user = User::factory()->create();
        $request = $this->ask();
        $wrong = $request['code'] === '99' ? '98' : '99';

        $this->actingAs($user);
        $component = Volt::test('pages.auth.qr-approve', ['token' => $request['token']])->set('typed', $wrong);

        $component->call('approve')->assertSee("That number doesn't match");
        $component->call('approve')->call('approve')->assertSet('outcome', 'blocked')->assertSee('AUTH-QR-CODE');
    }

    public function test_denying_on_the_phone_cancels_it(): void
    {
        $user = User::factory()->create();
        $request = $this->ask();

        $this->actingAs($user);
        Volt::test('pages.auth.qr-approve', ['token' => $request['token']])->call('deny')->assertSet('outcome', 'denied');

        $this->assertSame('denied', $this->qr->find($request['token'])['status']);
    }

    public function test_an_expired_link_says_so_with_its_guide(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('pages.auth.qr-approve', ['token' => str_repeat('b', 40)])
            ->assertSee('expired or was already used')
            ->assertSee('AUTH-QR-EXPIRED');
    }

    public function test_approving_is_rate_limited_per_account(): void
    {
        $user = User::factory()->create();
        RateLimiter::clear('qr-approve:'.$user->id);
        $request = $this->ask();
        $wrong = $request['code'] === '99' ? '98' : '99';

        $this->actingAs($user);
        $component = Volt::test('pages.auth.qr-approve', ['token' => $request['token']])->set('typed', $wrong);

        // Fresh requests each time, so only the per-account limit can stop it.
        foreach (range(1, 10) as $i) {
            $other = $this->ask();
            Volt::test('pages.auth.qr-approve', ['token' => $other['token']])->set('typed', $wrong)->call('approve');
        }

        $component->call('approve')->assertSee('Too many tries');
    }

    // ---- the link-device page and the menu

    public function test_the_link_device_page_needs_an_account_and_has_the_scanner(): void
    {
        $this->get(route('account.link-device'))->assertRedirect(route('login'));

        $html = $this->actingAs(User::factory()->create())->get(route('account.link-device'))->assertOk()->getContent();

        $this->assertStringContainsString("qrScanner('', null, 'login')", $html);
        $this->assertStringContainsString('Scan the login code', $html);
    }

    public function test_the_menus_offer_log_in_another_device(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('profile'))->assertOk()->getContent();

        $this->assertGreaterThanOrEqual(2, substr_count($html, route('account.link-device')), 'account menu and phone menu');
    }

    public function test_the_new_guides_exist_on_the_help_page(): void
    {
        $this->get('/help')->assertOk()
            ->assertSee('id="auth-qr-expired"', false)->assertSee('id="auth-qr-denied"', false)->assertSee('id="auth-qr-code"', false);
    }

    public function test_the_qr_popup_and_the_log_out_everywhere_confirmation_are_centered_both_ways(): void
    {
        $login = $this->get('/login')->getContent();
        $this->assertSame(1, preg_match_all('/class="my-auto w-full /', $login), 'the QR popup is centered');
        $this->assertStringNotContainsString('items-center justify-center" style', $login, 'with auto margins, not align-items, so tall content is never clipped at the top');

        $profile = $this->actingAs(User::factory()->create())->get(route('profile'))->getContent();
        // Three modals on the profile page: the two log-out-everywhere confirmations (menu and profile
        // section) are centered, and the delete-account one keeps its top-aligned layout.
        $this->assertSame(2, preg_match_all('/class="my-auto w-full /', $profile));
        $this->assertSame(3, preg_match_all('/fixed inset-0 overflow-y-auto/', $profile));
    }

    public function test_every_qr_message_with_a_code_links_to_its_guide(): void
    {
        // computer side: too many codes requested
        RateLimiter::clear('qr-start:session:'.session()->getId());
        $panel = Volt::test('auth.qr-login-panel');
        foreach (range(1, 11) as $i) {
            $panel->call('start');
        }
        $panel->assertSee('[AUTH-QR-LIMIT]');

        // phone side: wrong number, same device, too many tries
        $user = User::factory()->create();
        $this->actingAs($user);
        RateLimiter::clear('qr-approve:'.$user->id);
        $request = $this->ask();
        $wrong = $request['code'] === '99' ? '98' : '99';

        Volt::test('pages.auth.qr-approve', ['token' => $request['token']])->set('typed', $wrong)->call('approve')
            ->assertSee('[AUTH-QR-CODE]');

        foreach (range(1, 10) as $i) {
            RateLimiter::hit('qr-approve:'.$user->id, 600);
        }
        Volt::test('pages.auth.qr-approve', ['token' => $request['token']])->set('typed', $wrong)->call('approve')
            ->assertSee('Too many tries')->assertSee('[AUTH-QR-LIMIT]');
    }

    public function test_a_busy_approval_shows_its_guide_code(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        RateLimiter::clear('qr-approve:'.$user->id);

        // Hold the request's lock so the approval cannot take it (the service waits 3 s by default).
        $request = $this->ask();
        $held = Cache::lock('qr-login-lock:'.$request['token'], 10);
        $this->assertTrue($held->get());
        $this->app->instance(QrLogin::class, new QrLogin(lockWaitSeconds: 0));

        Volt::test('pages.auth.qr-approve', ['token' => $request['token']])->set('typed', $request['code'])->call('approve')
            ->assertSee('Press Approve again')->assertSee('[AUTH-QR-BUSY]');

        $held->release();
    }

    public function test_the_two_new_guides_are_on_the_help_page_for_both_roles(): void
    {
        $html = $this->get('/help')->assertOk()->getContent();

        foreach (['auth-qr-limit', 'auth-qr-busy'] as $anchor) {
            $this->assertStringContainsString('id="'.$anchor.'"', $html);
        }

        foreach (['AUTH-QR-LIMIT', 'AUTH-QR-BUSY'] as $code) {
            $entry = \App\Support\Errors\ErrorCatalog::find($code);
            $this->assertNotEmpty($entry['host']);
            $this->assertNotEmpty($entry['guest']);
        }
    }
}
