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

    public function test_the_client_reads_the_env_settings_but_ignores_unexpanded_placeholders(): void
    {
        $js = file_get_contents(resource_path('js/echo.js'));

        foreach (['VITE_REVERB_APP_KEY', 'VITE_REVERB_HOST', 'VITE_REVERB_PORT', 'VITE_REVERB_SCHEME'] as $variable) {
            $this->assertStringContainsString($variable, $js, "{$variable} is still the first source");
        }

        // Production passed these through as the literal text "${REVERB_HOST}"; such a value must not be used.
        $this->assertStringContainsString("includes('\${')", $js);
        $this->assertStringContainsString('reverb-key', $js, 'and the meta tag / page host are the fallback');
    }
}
