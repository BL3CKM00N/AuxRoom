<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharePreviewTest extends TestCase
{
    use RefreshDatabase;

    private function room(array $attributes = []): Room
    {
        $host = User::factory()->create(['name' => 'Quentin Testhost']);

        return Room::create($attributes + [
            'invite_code' => 'AAAAAA-BBBBBB-CCCCCC',
            'host_id' => $host->id,
            'playback_provider_id' => $host->id,
        ])->refresh();
    }

    public function test_an_invite_link_previews_as_an_invitation_from_the_host_and_keeps_its_code(): void
    {
        $room = $this->room();
        $url = route('join', ['code' => $room->invite_code]);

        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:title" content="Quentin Testhost invited you to their AuxRoom">', $html);
        $this->assertStringContainsString('<meta name="twitter:title" content="Quentin Testhost invited you to their AuxRoom">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="'.$url.'">', $html, 'the code must survive in og:url');
        $this->assertStringContainsString('<link rel="canonical" href="'.$url.'">', $html);
        $this->assertStringContainsString('<title>Quentin Testhost invited you to their AuxRoom</title>', $html);
    }

    public function test_a_lowercase_code_still_finds_the_room(): void
    {
        $this->room();

        $this->get('/join?code=aaaaaa-bbbbbb-cccccc')->assertOk()->assertSee('Quentin Testhost invited you', false);
    }

    public function test_the_card_never_shows_the_current_track_or_the_code_itself(): void
    {
        $room = $this->room(['now_playing_track_id' => 't1', 'now_playing_name' => 'Zyxwv Secret Song', 'is_playing' => true]);

        $html = $this->get(route('join', ['code' => $room->invite_code]))->getContent();

        $this->assertStringNotContainsString('Zyxwv Secret Song', $html);
        // The code is in the og:url (the link itself) but not in the human-readable text of the card.
        $this->assertStringNotContainsString('content="Quentin Testhost invited you to their AuxRoom AAAAAA', $html);
        $this->assertDoesNotMatchRegularExpression('/og:description" content="[^"]*AAAAAA/', $html);
    }

    public function test_a_closed_or_unknown_code_says_the_room_has_ended_and_is_not_indexed(): void
    {
        $room = $this->room(['closed_at' => now()]);

        foreach ([$room->invite_code, 'NOPE00-NOPE00-NOPE00'] as $code) {
            $html = $this->get(route('join', ['code' => $code]))->assertOk()->getContent();

            $this->assertStringContainsString('<meta property="og:title" content="This AuxRoom has ended">', $html);
            $this->assertStringContainsString('<meta name="robots" content="noindex">', $html);
            $this->assertStringNotContainsString('invited you', $html);
        }
    }

    public function test_the_plain_join_page_previews_as_itself(): void
    {
        $html = $this->get('/join')->getContent();

        $this->assertStringContainsString('<meta property="og:title" content="Join a room on AuxRoom">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="'.route('join').'">', $html);
    }

    public function test_the_party_screen_link_previews_as_the_party_screen_for_that_room(): void
    {
        $room = $this->room();
        $url = route('rooms.party', $room);

        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString("<meta property=\"og:title\" content=\"Quentin Testhost&#039;s AuxRoom party screen\">", $html);
        $this->assertStringContainsString('<meta property="og:url" content="'.$url.'">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.$url.'">', $html);
    }

    public function test_the_home_page_keeps_its_own_generic_card(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('<meta property="og:title" content="AuxRoom">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/').'">', $html);
        $this->assertStringNotContainsString('noindex', $html);
    }

    public function test_every_card_carries_the_full_tag_set(): void
    {
        $html = $this->get('/join')->getContent();

        foreach (['og:type', 'og:site_name', 'og:locale', 'og:title', 'og:description', 'og:url', 'og:image:secure_url', 'og:image:width', 'twitter:card', 'twitter:image', 'twitter:image:alt', 'name="description"'] as $needle) {
            $this->assertStringContainsString($needle, $html, "missing {$needle}");
        }
    }

    public function test_an_open_invite_points_its_cards_at_the_room_specific_pictures(): void
    {
        $room = $this->room();

        $html = $this->get(route('join', ['code' => $room->invite_code]))->getContent();

        $wide = route('share.image', ['code' => $room->invite_code, 'shape' => 'wide', 'v' => substr(md5('Quentin Testhost'), 0, 8)]);
        $square = route('share.image', ['code' => $room->invite_code, 'shape' => 'square', 'v' => substr(md5('Quentin Testhost'), 0, 8)]);

        $this->assertStringContainsString('<meta property="og:image" content="'.$wide.'">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="'.$square.'">', $html);
        $this->assertStringContainsString('<meta name="twitter:image" content="'.$wide.'">', $html);
    }

    public function test_closed_rooms_and_other_pages_keep_the_shared_pictures(): void
    {
        $room = $this->room(['closed_at' => now()]);

        foreach ([route('join', ['code' => $room->invite_code]), route('join'), url('/')] as $url) {
            $html = $this->get($url)->getContent();

            $this->assertStringContainsString('content="'.asset('images/og-image.jpg').'"', $html);
            $this->assertStringNotContainsString('/share/', $html);
        }
    }

    public function test_the_picture_endpoint_serves_a_real_jpeg_in_both_shapes(): void
    {
        $room = $this->room();

        foreach (['wide' => [1200, 630], 'square' => [1200, 1200]] as $shape => [$w, $h]) {
            $response = $this->get(route('share.image', ['code' => $room->invite_code, 'shape' => $shape]))
                ->assertOk()
                ->assertHeader('Content-Type', 'image/jpeg');

            $info = getimagesizefromstring($response->getContent());
            $this->assertSame([$w, $h], [$info[0], $info[1]]);
            $this->assertSame('image/jpeg', $info['mime']);
            $this->assertLessThan(300 * 1024, strlen($response->getContent()), 'WhatsApp skips larger preview images');
        }
    }

    public function test_there_is_no_picture_for_a_closed_unknown_or_malformed_request(): void
    {
        $open = $this->room();
        $closed = $this->room(['invite_code' => 'DDDDDD-EEEEEE-FFFFFF', 'closed_at' => now()]);

        $this->get(route('share.image', ['code' => $closed->invite_code, 'shape' => 'wide']))->assertNotFound();
        $this->get('/share/NOPE00-NOPE00-NOPE00/wide.jpg')->assertNotFound();
        $this->get('/share/'.$open->invite_code.'/huge.jpg')->assertNotFound();
    }

    public function test_names_the_font_cannot_draw_are_dropped_instead_of_showing_boxes(): void
    {
        $this->assertSame('Daan van Uden', \App\Services\ShareImage::drawableName('Daan van Uden 🎧'));
        $this->assertSame('Jose Muller', \App\Services\ShareImage::drawableName("  Jose   Muller  "));
        $this->assertSame('Someone', \App\Services\ShareImage::drawableName('🎧🎧'));
        $this->assertSame('Someone', \App\Services\ShareImage::drawableName(''));
    }

    public function test_the_background_is_shielded_from_livewire_rerenders_and_uses_the_prerendered_pictures(): void
    {
        $room = $this->room();

        $html = $this->get(route('rooms.party', $room))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div class="green-waves"[^>]*wire:ignore/', $html, 'Livewire would reset the classes the background script adds');
        $this->assertStringContainsString(asset('images/aurora-a.jpg'), $html);
        $this->assertStringContainsString(asset('images/aurora-b.jpg'), $html);
        $this->assertStringNotContainsString('<animate', $html, 'the per-frame SVG morph must stay gone');
        $this->assertFileExists(public_path('images/aurora-a.jpg'));
        $this->assertFileExists(public_path('images/aurora-b.jpg'));
        $this->assertFileExists(public_path('images/grain.png'));
    }
}
