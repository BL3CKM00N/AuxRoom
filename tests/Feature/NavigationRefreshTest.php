<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use App\Services\RoomMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The menu refreshes itself over Livewire every 10 seconds; it must look the same afterwards. */
class NavigationRefreshTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = User::factory()->create();
        $this->room = Room::create(['invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $this->host->id, 'playback_provider_id' => $this->host->id])->refresh();
        app(RoomMembership::class)->joinAsHost($this->room);
    }

    /** What the browser gets back when the menu polls: the same request Livewire's own script makes. */
    private function refreshedMenuHtml(string $url): string
    {
        $page = $this->actingAs($this->host)->get($url)->assertOk()->getContent();

        preg_match('/<nav [^>]*wire:snapshot="([^"]+)"/', $page, $match);
        $this->assertNotEmpty($match, 'the menu is a Livewire component');

        $response = $this->actingAs($this->host)->postJson('/livewire/update', ['components' => [[
            'snapshot' => html_entity_decode($match[1]),
            'updates' => [],
            'calls' => [],
        ]]], ['X-Livewire' => 'true'])->assertOk();

        return $response->json('components.0.effects.html');
    }

    public function test_the_live_dot_is_still_there_after_the_menu_refreshes(): void
    {
        $this->assertStringContainsString('liveStatus(', $this->refreshedMenuHtml(route('dashboard')));
        $this->assertStringContainsString('liveStatus(', $this->refreshedMenuHtml(route('rooms.show', $this->room)));
    }

    public function test_the_dot_is_not_on_pages_that_are_not_a_room(): void
    {
        $this->assertStringNotContainsString('liveStatus(', $this->refreshedMenuHtml(route('profile')));
    }

    public function test_the_current_tab_stays_highlighted_after_a_refresh(): void
    {
        $html = $this->refreshedMenuHtml(route('dashboard', ['tab' => 'guests']));

        // The highlighted link has the accent border; exactly one link per list is highlighted, and it is Guests.
        preg_match_all('/<a [^>]*border-aux-accent[^>]*>\s*(?:<span[^>]*>)?\s*([A-Za-z ]+?)\s*(?:<|$)/', $html, $active);
        $this->assertSame(['Guests'], array_values(array_unique($active[1])));
    }

    public function test_the_default_tab_is_highlighted_when_the_url_has_none(): void
    {
        $html = $this->refreshedMenuHtml(route('dashboard'));

        preg_match_all('/<a [^>]*border-aux-accent[^>]*>\s*(?:<span[^>]*>)?\s*([A-Za-z ]+?)\s*(?:<|$)/', $html, $active);
        $this->assertSame(['Now Playing'], array_values(array_unique($active[1])));
    }

    public function test_the_remembered_page_cannot_be_changed_from_the_browser(): void
    {
        $page = $this->actingAs($this->host)->get(route('dashboard'))->getContent();
        preg_match('/<nav [^>]*wire:snapshot="([^"]+)"/', $page, $match);

        $this->actingAs($this->host)->postJson('/livewire/update', ['components' => [[
            'snapshot' => html_entity_decode($match[1]),
            'updates' => ['routeName' => 'profile'],
            'calls' => [],
        ]]], ['X-Livewire' => 'true'])->assertStatus(500);
    }
}
