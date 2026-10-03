<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\ShowRoom;
use App\Models\Room;
use App\Models\SpotifyAccount;
use App\Models\User;
use App\Services\RoomMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MobileLayoutTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int, array>|null $devices what Spotify lists as devices; null means one phone */
    private function html(array $roomAttributes = [], ?array $devices = null): string
    {
        $host = User::factory()->create();
        SpotifyAccount::create([
            'user_id' => $host->id, 'client_id' => 'id', 'client_secret' => 'secret',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
        ]);
        $room = Room::create($roomAttributes + ['invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $host->id, 'playback_provider_id' => $host->id])->refresh();
        app(RoomMembership::class)->joinAsHost($room);

        Http::fake([
            'api.spotify.com/v1/me/player/queue' => Http::response(['queue' => []]),
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => $devices ?? [
                ['id' => 'dev1', 'name' => "Daan's iPhone", 'type' => 'Smartphone', 'is_active' => true, 'supports_volume' => true],
            ]]),
            'api.spotify.com/v1/me/player' => Http::response('', 204),
        ]);

        return Livewire::actingAs($host)->test(ShowRoom::class, ['room' => $room])->html();
    }

    public function test_the_bottom_player_bar_only_exists_from_tablet_width_up(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression('/<div class="fixed bottom-0[^"]*\bhidden md:flex\b/', $html);
        $this->assertStringNotContainsString('md:hidden relative shrink-0', $html, 'the old mobile menu behind the volume button is gone');
        $this->assertStringContainsString('pb-8 md:pb-32', $html, 'no space is reserved for a bar that is not there on mobile');
    }

    public function test_device_and_volume_live_in_the_now_playing_card_on_mobile(): void
    {
        $html = $this->html();

        $card = strstr($html, 'Playing on');
        $this->assertNotFalse($card);
        $this->assertStringContainsString('class="md:hidden mt-5 pt-4 border-t', $html);
        $this->assertStringContainsString('Daan', $card, 'the device list is offered in the card');
        $this->assertStringContainsString('wire:change="setVolume($event.target.value)"', $card, 'and so is the volume slider');
    }

    public function test_the_card_keeps_device_and_volume_even_when_nothing_is_playing(): void
    {
        $html = $this->html(['now_playing_track_id' => null, 'is_playing' => false]);

        $this->assertStringContainsString('Playing on', $html);
        $this->assertStringContainsString('Volume', $html);
    }

    public function test_a_device_name_with_an_apostrophe_cannot_break_the_click_handler(): void
    {
        $html = $this->html();

        preg_match_all('/wire:click="selectDevice\(([^"]*)"/', $html, $m);
        $this->assertNotEmpty($m[1]);

        foreach ($m[1] as $arguments) {
            $this->assertStringNotContainsString("Daan's", html_entity_decode($arguments), 'a raw apostrophe would end the JS string early');
        }
    }

    public function test_on_mobile_find_music_and_playlist_come_before_the_queue(): void
    {
        $html = $this->html();

        // Source order is the lg two-column layout; the order-N classes carry the mobile order.
        $this->assertStringContainsString('contents lg:block lg:col-span-2 lg:space-y-6', $html);
        $this->assertStringContainsString('contents lg:block lg:space-y-6', $html);

        $expected = [1 => 'Now spinning', 2 => 'Find Music', 3 => 'Choose a playlist', 4 => 'Up Next', 5 => 'Emergency stop'];

        foreach ($expected as $n => $text) {
            $start = strpos($html, 'class="order-'.$n.' ');
            $this->assertNotFalse($start, "a card has order-{$n}");

            $next = strpos($html, 'class="order-', $start + 10);
            $card = substr($html, $start, ($next === false ? strlen($html) : $next) - $start);

            // Each ordered card holds its own heading (now playing shows a status label instead of one).
            $this->assertStringContainsString($n === 1 ? 'Playing on' : $text, $card, "order-{$n} is the {$text} card");
        }
    }

    public function test_the_device_and_volume_panel_starts_closed_and_a_speaker_icon_opens_it(): void
    {
        $html = $this->html(['now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha Anthem', 'now_playing_duration_ms' => 180000, 'is_playing' => true, 'now_playing_started_at' => now()]);

        $this->assertStringContainsString('x-data="{ tools: false }"', $html, 'closed on load');
        $this->assertMatchesRegularExpression('/<div x-show="tools" x-cloak x-collapse class="md:hidden mt-5/', $html, 'the panel is hidden until tools is true');
        $this->assertStringContainsString('@click="tools = !tools"', $html);
        $this->assertStringContainsString('aria-label="Device and volume"', $html);
    }

    public function test_the_speaker_icon_is_there_when_nothing_is_playing_too(): void
    {
        $html = $this->html(['now_playing_track_id' => null, 'is_playing' => false]);

        $this->assertStringContainsString('@click="tools = !tools"', $html, 'a host picks the device before anything plays');
    }

    public function test_choosing_a_device_closes_the_panel(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression('/wire:click="selectDevice\([^"]*"[^>]*@click="open = false; tools = false"/', $html);
    }

    public function test_with_no_device_at_all_every_playback_and_queue_control_is_hidden(): void
    {
        $html = $this->html(['now_playing_track_id' => null, 'is_playing' => false], devices: []);
        $card = $this->card($html);

        $this->assertStringContainsString('No device found', $card);
        $this->assertStringContainsString('Open Spotify on a phone, computer, or speaker', $card);

        foreach (['play', 'pause', 'previous', 'skip', 'toggleShuffle', 'toggleRepeat', 'setVolume', 'openPlaylistPicker'] as $action) {
            $this->assertStringNotContainsString('wire:click="'.$action, $card, "{$action} cannot do anything without a device");
        }
        $this->assertStringNotContainsString('aria-label="Device and volume"', $card);
        $this->assertStringNotContainsString('x-collapse', $card, 'no device and volume panel either');

        $page = substr($html, 0, strpos($html, 'Emergency stop'));
        $this->assertStringNotContainsString('Find Music', $page);
        $this->assertStringNotContainsString('Choose a playlist', $page);
        $this->assertStringNotContainsString('Up Next', $page);
        $this->assertStringContainsString('Emergency stop', $html, 'the host keeps the safety control');
    }

    public function test_a_failed_device_lookup_while_a_song_plays_does_not_hide_the_controls(): void
    {
        $html = $this->html(
            ['now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha Anthem', 'now_playing_duration_ms' => 180000, 'is_playing' => true, 'now_playing_started_at' => now()],
            devices: [],
        );

        $card = $this->card($html);
        $this->assertStringContainsString('wire:click="pause"', $card);
        $this->assertStringContainsString('wire:click="previous"', $card);
        $this->assertStringContainsString('Find Music', $html);
    }

    public function test_with_a_device_listed_but_idle_search_and_the_playlist_picker_stay_but_the_queue_list_goes(): void
    {
        $html = $this->html([
            'now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha Anthem', 'now_playing_duration_ms' => 180000,
            'is_playing' => false, 'playback_inactive_at' => now(),
        ]);
        $page = substr($html, 0, strpos($html, 'Emergency stop'));

        $this->assertStringContainsString('Find Music', $page, 'adding a song can wake the device');
        $this->assertStringContainsString('Choose a playlist', $page);
        $this->assertStringNotContainsString('Up Next', $page, 'nothing is queued on an inactive player');
    }

    /** The attribute list of the first button that calls $action. */
    private function button(string $html, string $action): string
    {
        $this->assertSame(1, preg_match('/<button[^>]*wire:click="'.$action.'"[^>]*>/s', $html, $m), "a {$action} button is rendered");

        return $m[0];
    }

    private function isDisabled(string $button): bool
    {
        return preg_match('/\sdisabled[\s=>]/', $button) === 1;
    }

    /** Only the Now Playing card: the desktop bar further down the page has its own copies of these buttons. */
    private function card(string $html): string
    {
        // The card ends where the next card begins; which cards exist depends on the state.
        $ends = array_filter(array_map(fn (string $marker) => strpos($html, $marker), ['Find Music', 'Up Next', 'Emergency stop']), fn ($p) => $p !== false);
        $this->assertNotEmpty($ends);

        return substr($html, 0, min($ends));
    }

    public function test_with_a_device_listed_but_idle_only_play_and_the_device_button_are_offered_in_the_card(): void
    {
        // Spotify reported nothing active: the track is kept for resuming but not shown (see Room::playbackInactive()).
        $card = $this->card($this->html([
            'now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha Anthem', 'now_playing_duration_ms' => 180000,
            'is_playing' => false, 'playback_inactive_at' => now(),
        ]));

        // A device is listed but nothing is active: not "no device", since pressing Play can wake it.
        $this->assertStringContainsString('Not playing', $card);
        $this->assertStringNotContainsString('No device found', $card);

        foreach (['previous', 'skip', 'toggleShuffle', 'toggleRepeat'] as $action) {
            $this->assertStringNotContainsString('wire:click="'.$action.'"', $card, "{$action} has nothing to act on without a device");
        }

        $this->assertFalse($this->isDisabled($this->button($card, 'play')), 'the retained track can still be resumed');
        $this->assertStringContainsString('aria-label="Device and volume"', $card, 'the host still needs to pick a device');
    }

    public function test_with_nothing_to_play_the_controls_are_there_and_play_is_disabled(): void
    {
        $html = $this->card($this->html(['now_playing_track_id' => null, 'is_playing' => false]));

        $this->assertTrue($this->isDisabled($this->button($html, 'previous')));
        $this->assertTrue($this->isDisabled($this->button($html, 'skip')));
        $this->assertTrue($this->isDisabled($this->button($html, 'play')), 'nothing queued, no playlist, no stored track');
    }

    public function test_while_playing_previous_and_next_are_enabled(): void
    {
        $html = $this->card($this->html(['now_playing_track_id' => 't1', 'now_playing_name' => 'Alpha Anthem', 'now_playing_duration_ms' => 180000, 'is_playing' => true, 'now_playing_started_at' => now()]));

        $this->assertFalse($this->isDisabled($this->button($html, 'previous')));
        $this->assertFalse($this->isDisabled($this->button($html, 'skip')));
    }
}
