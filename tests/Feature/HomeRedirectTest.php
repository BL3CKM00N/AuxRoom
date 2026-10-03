<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use App\Services\RoomMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    private function hostedRoom(User $host, array $attributes = []): Room
    {
        $room = Room::create($attributes + [
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC',
            'host_id' => $host->id,
            'playback_provider_id' => $host->id,
        ]);
        app(RoomMembership::class)->joinAsHost($room);

        return $room;
    }

    public function test_a_visitor_sees_the_landing_page(): void
    {
        $this->get('/')->assertOk()->assertSee('Host a room')->assertSee('Log in');
    }

    public function test_the_host_a_room_button_goes_to_login(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('login'), '#').'"[^>]*>\s*Host a room\s*</a>#', $html);
    }

    public function test_logged_in_without_a_room_goes_straight_to_room_creation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('rooms.create'));
    }

    public function test_logged_in_with_an_open_room_goes_to_that_room(): void
    {
        $host = User::factory()->create();
        $this->hostedRoom($host);

        $this->actingAs($host)->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_a_closed_room_does_not_count_so_creation_is_offered_again(): void
    {
        $host = User::factory()->create();
        $this->hostedRoom($host, ['closed_at' => now()]);

        $this->actingAs($host)->get('/')->assertRedirect(route('rooms.create'));
    }

    public function test_someone_else_hosting_a_room_does_not_send_you_there(): void
    {
        $this->hostedRoom(User::factory()->create());

        $this->actingAs(User::factory()->create())->get('/')->assertRedirect(route('rooms.create'));
    }

    public function test_the_redirect_lands_somewhere_that_works(): void
    {
        $host = User::factory()->create();

        $this->actingAs($host)->followingRedirects()->get('/')->assertOk();

        $this->hostedRoom($host);

        $this->actingAs($host)->followingRedirects()->get('/')->assertOk();
    }
}
