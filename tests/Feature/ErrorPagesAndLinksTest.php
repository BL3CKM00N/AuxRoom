<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ErrorPagesAndLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_page_explains_itself_and_carries_a_reference(): void
    {
        $response = $this->get('/definitely-not-a-page')->assertNotFound()
            ->assertSee('Learn more')
            ->assertSee('What you can try')
            ->assertSee('Reference');

        $ref = $response->headers->get('X-Request-Ref');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $ref);
        $response->assertSee($ref);
        $response->assertSee(route('help').'#http-404', false);
        $response->assertSee('Copy details');
    }

    public function test_the_steps_are_folded_behind_learn_more_not_shown_up_front(): void
    {
        $html = $this->get('/definitely-not-a-page')->assertNotFound()->getContent();

        $this->assertMatchesRegularExpression('#<details[^>]*>\s*<summary[^>]*>.*?Learn more.*?</summary>.*?What you can try.*?</details>#s', $html);
        $this->assertDoesNotMatchRegularExpression('#</details>.*?What you can try#s', $html, 'nothing is shown outside the toggle');
        $this->assertStringNotContainsString(' open', substr($html, strpos($html, '<details'), 40), 'closed on load');
    }

    public function test_the_reference_is_in_the_logs_for_that_request(): void
    {
        $response = $this->get('/definitely-not-a-page');

        $this->assertSame($response->headers->get('X-Request-Ref'), Log::sharedContext()['ref'] ?? null);
    }

    public function test_every_request_gets_its_own_reference(): void
    {
        $a = $this->get('/help')->headers->get('X-Request-Ref');
        $b = $this->get('/help')->headers->get('X-Request-Ref');

        $this->assertNotSame($a, $b);
    }

    public function test_a_wrong_method_shows_the_405_guide(): void
    {
        $this->post('/help')->assertStatus(405)->assertSee('What you can try')->assertSee('#http-405', false);
    }

    public function test_closing_someone_elses_room_shows_the_403_guide(): void
    {
        $owner = User::factory()->create();
        $room = Room::create(['invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $owner->id, 'playback_provider_id' => $owner->id]);

        $this->actingAs(User::factory()->create())->delete(route('rooms.destroy', $room))
            ->assertForbidden()->assertSee('#http-403', false)->assertSee('only the host can do');
    }

    public function test_a_wrong_invite_code_links_to_its_guide(): void
    {
        $this->from(route('join'))->followingRedirects()->post('/join', ['name' => 'Sam', 'invite_code' => 'AAAAAA-AAAAAA-AAAAAA'])
            ->assertSee("That invite code doesn't match an open room.")
            ->assertSee(route('help').'#join-code', false);
    }

    public function test_the_join_page_has_help_links_for_the_scanner_and_a_dropped_connection(): void
    {
        $html = $this->get('/join')->assertOk()->getContent();

        foreach (['qr-denied', 'qr-unavailable', 'qr-nopicture', 'qr-error', 'net-offline'] as $anchor) {
            $this->assertStringContainsString(route('help').'#'.$anchor, $html, "{$anchor} is linked");
        }
        $this->assertStringContainsString('Trouble joining?', $html);
    }

    public function test_the_landing_page_links_to_help(): void
    {
        $this->get('/')->assertSee('>Help</a>', false);
    }

    public function test_the_room_creation_page_links_location_errors_to_their_guides(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('rooms.create'))->assertOk()->getContent();

        foreach (['loc-unsupported', 'loc-failed', 'loc-invalid', 'loc-unset'] as $anchor) {
            $this->assertStringContainsString($anchor, $html);
        }
    }

    public function test_the_help_page_lists_the_new_groups(): void
    {
        $this->get('/help')->assertOk()
            ->assertSee('Page errors')->assertSee('Scanning a QR code')->assertSee('Joining a room')
            ->assertSee('id="http-500"', false)->assertSee('id="net-offline"', false);
    }

    private function helpLinks(string $html): int
    {
        return substr_count($html, 'href="'.route('help').'"');
    }

    public function test_help_is_in_every_logged_in_menu(): void
    {
        $host = User::factory()->create();
        $room = Room::create(['invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $host->id, 'playback_provider_id' => $host->id]);
        app(\App\Services\RoomMembership::class)->joinAsHost($room);

        $dashboard = $this->actingAs($host)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertGreaterThanOrEqual(3, $this->helpLinks($dashboard), 'top bar, account menu and phone menu');
        $this->assertStringContainsString('Help with errors', $dashboard);

        foreach ([route('profile'), route('rooms.create')] as $url) {
            // A host with an open room is bounced from the creation page; a fresh account is not.
            $other = User::factory()->create();
            $html = $this->actingAs($other)->get($url)->assertOk()->getContent();
            $this->assertGreaterThanOrEqual(3, $this->helpLinks($html), "{$url} has the Help links");
        }
    }

    public function test_guests_in_a_room_can_reach_help_from_the_top_bar_and_the_phone_menu(): void
    {
        $host = User::factory()->create();
        $room = Room::create(['invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $host->id, 'playback_provider_id' => $host->id]);
        app(\App\Services\RoomMembership::class)->joinAsHost($room);
        ['guest_token' => $token] = app(\App\Services\RoomMembership::class)->joinAsGuest($room, 'Sam');

        $html = $this->withCookie(\App\Services\RoomMembership::cookieName($room), $token)
            ->get(route('rooms.show', $room));
        $html = $html->assertOk()->getContent();

        $this->assertGreaterThanOrEqual(2, $this->helpLinks($html));
    }
}
