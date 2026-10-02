<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JoinPageTest extends TestCase
{
    // The page now looks the code up to word its link preview.
    use RefreshDatabase;

    public function test_join_page_offers_qr_scanning_wired_to_the_invite_code_field(): void
    {
        $html = $this->get('/join')->assertOk()->getContent();

        $this->assertStringContainsString('Scan QR code', $html);
        $this->assertStringContainsString("qrScanner('invite_code', 'name')", $html);
        $this->assertStringContainsString('id="invite_code"', $html);
    }

    public function test_join_page_prefills_the_code_from_the_link(): void
    {
        $this->get('/join?code=ABCDEF-GHJKMN-PQRSTU')
            ->assertOk()
            ->assertSee('value="ABCDEF-GHJKMN-PQRSTU"', false);
    }

    public function test_alpine_actually_loads_on_the_plain_join_page(): void
    {
        // The scanner is x-data; on a page with no Livewire component Alpine
        // only exists if the layout includes Livewire's scripts itself.
        $this->assertSame(1, substr_count($this->get('/join')->getContent(), '/livewire/livewire.js'));
    }

    public function test_livewire_scripts_are_not_injected_twice_on_pages_that_already_get_them(): void
    {
        foreach (['/login', '/register'] as $path) {
            $this->assertSame(1, substr_count($this->get($path)->getContent(), '/livewire/livewire.js'), $path);
        }
    }

    public function test_scanner_overlay_avoids_the_patterns_that_black_out_ios_safari(): void
    {
        $html = $this->get('/join')->getContent();

        // Teleported out of the overflow-hidden, rounded form card.
        $this->assertStringContainsString('x-teleport="body"', $html);

        // No giant spread box-shadow cutout (a ~20,000px layer on iOS).
        $this->assertStringNotContainsString('9999px', $html);

        // Attached-at-attach-time attributes iOS needs on the video element.
        $this->assertMatchesRegularExpression('/<video[^>]*\bplaysinline\b[^>]*\bmuted\b/', $html);
    }

    public function test_scanner_shows_a_canvas_preview_not_the_video_element(): void
    {
        $html = $this->get('/join')->getContent();

        // The visible picture is a canvas painted from the video, because a
        // live camera <video> can paint black on iPhones while delivering frames.
        $this->assertStringContainsString('x-ref="preview"', $html);
        $this->assertDoesNotMatchRegularExpression('/<video[^>]*x-ref="video"[^>]*\binset-0\b/', $html);
        $this->assertDoesNotMatchRegularExpression('/<video[^>]*\bobject-cover\b/', $html);
    }
}
