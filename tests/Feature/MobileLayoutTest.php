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

    private function html(array $roomAttributes = []): string
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
            'api.spotify.com/v1/me/player/devices' => Http::response(['devices' => [
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
}
