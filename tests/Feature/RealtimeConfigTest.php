<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_hand_the_browser_the_reverb_key_through_a_meta_tag(): void
    {
        config(['reverb.apps.apps.0.key' => 'publickey123']);

        foreach (['/', '/join', '/login'] as $url) {
            $this->get($url)->assertOk()->assertSee('<meta name="reverb-key" content="publickey123">', false);
        }
    }

    public function test_without_a_key_there_is_no_tag_so_the_browser_does_not_try_to_connect(): void
    {
        config(['reverb.apps.apps.0.key' => null]);

        $this->get('/join')->assertOk()->assertDontSee('reverb-key', false);
    }

    public function test_the_client_no_longer_depends_on_build_time_reverb_variables(): void
    {
        $js = file_get_contents(resource_path('js/echo.js'));

        $this->assertStringNotContainsString('VITE_REVERB', $js, 'production never expanded these; the browser tried to connect to "${REVERB_HOST}"');
        $this->assertStringContainsString('reverb-key', $js);
    }
}
