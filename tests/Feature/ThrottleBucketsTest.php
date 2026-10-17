<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * An unnamed `throttle:10,1` counts per visitor, not per route: every route that uses one shares the same counter.
 * The page's clock pings (/time, five per page load) were using up the join form's ten, so a guest got a 429
 * right after opening the page. Each limit now has a name, and a name is its own counter.
 */
class ThrottleBucketsTest extends TestCase
{
    use RefreshDatabase;

    private function openRoom(): Room
    {
        $host = User::factory()->create();

        return Room::create(['invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $host->id, 'playback_provider_id' => $host->id]);
    }

    public function test_a_guest_can_join_right_after_the_page_has_pinged_the_clock(): void
    {
        $room = $this->openRoom();

        // A few page loads' worth of clock pings, health checks and picture fetches from the same address.
        for ($i = 0; $i < 25; $i++) {
            $this->getJson('/time')->assertOk();
        }

        $this->post('/join', ['name' => 'Guest', 'invite_code' => $room->invite_code])->assertRedirect(route('rooms.show', $room));
    }

    public function test_the_join_form_still_has_its_own_limit(): void
    {
        $room = $this->openRoom();

        // Sixty a minute from one address: a whole party arriving on one Wi-Fi fits.
        for ($i = 0; $i < 60; $i++) {
            $this->post('/join', ['name' => 'Guest', 'invite_code' => $room->invite_code])->assertRedirect();
        }

        $this->post('/join', ['name' => 'Guest', 'invite_code' => $room->invite_code])->assertStatus(429);
    }

    public function test_another_limit_filling_up_does_not_block_the_join_form(): void
    {
        $room = $this->openRoom();

        // The picture route is limited to 120 a minute; use it up completely.
        for ($i = 0; $i < 121; $i++) {
            $this->get('/share/'.$room->invite_code.'/wide.jpg');
        }

        $this->post('/join', ['name' => 'Guest', 'invite_code' => $room->invite_code])->assertRedirect(route('rooms.show', $room));
    }

    public function test_the_clock_route_has_its_own_generous_limit(): void
    {
        for ($i = 0; $i < 600; $i++) {
            $this->getJson('/time')->assertOk();
        }

        $this->getJson('/time')->assertStatus(429);
    }

    public function test_no_route_uses_an_unnamed_throttle(): void
    {
        $unnamed = collect(app('router')->getRoutes()->getRoutes())
            // Livewire's own upload route has a numbered limit of its own that nothing else shares or uses.
            ->reject(fn ($route) => str_starts_with($route->uri(), 'livewire/'))
            ->filter(fn ($route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && preg_match('/^throttle:\d/', $m)))
            ->map(fn ($route) => $route->uri().' ('.implode(',', $route->methods()).')')
            ->values()->all();

        $this->assertSame([], $unnamed, 'a numbered throttle shares one counter between all routes that use it; give the limit a name (AppServiceProvider::defineRateLimits)');
    }
}
