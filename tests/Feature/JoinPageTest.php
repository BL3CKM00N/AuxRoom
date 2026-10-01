<?php

namespace Tests\Feature;

use Tests\TestCase;

class JoinPageTest extends TestCase
{
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
}
