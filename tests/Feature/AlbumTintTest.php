<?php

namespace Tests\Feature;

use App\Events\RoomUpdated;
use App\Livewire\Dashboard\PartyScreen;
use App\Models\Room;
use App\Models\User;
use App\Services\AlbumTint;
use App\Services\Spotify\PlaybackSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** The party screen's aurora takes its colour from the album art. */
class AlbumTintTest extends TestCase
{
    use RefreshDatabase;

    private const ART = 'https://i.scdn.co/image/abc123';

    /** @param  array<int, array{0: int, 1: int, 2: int}>  $colors  one per vertical stripe, equal widths */
    private function png(array $colors): string
    {
        $image = imagecreatetruecolor(24, 24);
        $stripe = intdiv(24, count($colors));

        foreach ($colors as $i => [$r, $g, $b]) {
            imagefilledrectangle($image, $i * $stripe, 0, $i === count($colors) - 1 ? 23 : ($i + 1) * $stripe - 1, 23, imagecolorallocate($image, $r, $g, $b));
        }

        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    private function room(array $attributes = []): Room
    {
        $host = User::factory()->create();

        return Room::create($attributes + ['invite_code' => 'AAAAAA-BBBBBB-CCCCCC', 'host_id' => $host->id, 'playback_provider_id' => $host->id])->refresh();
    }

    public function test_it_finds_the_hue_and_vividness_of_the_art(): void
    {
        $tint = new AlbumTint;

        $red = $tint->fromImageData($this->png([[220, 20, 20]]));
        $this->assertSame(0, $red['h']);
        $this->assertGreaterThan(80, $red['s']);

        $blue = $tint->fromImageData($this->png([[20, 40, 230]]));
        $this->assertEqualsWithDelta(230, $blue['h'], 10);

        $pale = $tint->fromImageData($this->png([[230, 200, 200]]));
        $this->assertLessThan(25, $pale['s'], 'a washed-out cover is not very vivid');
    }

    public function test_the_most_vivid_part_wins_over_gray_and_dark_areas(): void
    {
        $tint = new AlbumTint;

        $mostlyGray = $tint->fromImageData($this->png([[128, 128, 128], [128, 128, 128], [20, 200, 40]]));
        $this->assertEqualsWithDelta(125, $mostlyGray['h'], 12, 'the green stripe sets the hue');

        $blackAndWhite = $tint->fromImageData($this->png([[0, 0, 0], [255, 255, 255], [128, 128, 128]]));
        $this->assertSame(['h' => 0, 's' => 0], $blackAndWhite);

        $this->assertSame([], $tint->fromImageData('not an image'));
    }

    public function test_the_css_values_rotate_from_the_auroras_green(): void
    {
        $this->assertSame(['hue' => 0, 'sat' => 1.3], AlbumTint::css(['h' => 153, 's' => 100]));
        $this->assertSame(['hue' => -153, 'sat' => 0.3], AlbumTint::css(['h' => 0, 's' => 0]));
        $this->assertSame(87, AlbumTint::css(['h' => 240, 's' => 50])['hue']);
        $this->assertSame(-180, abs(AlbumTint::css(['h' => 333, 's' => 50])['hue']) * -1, 'the far side of the wheel');

        $this->assertNull(AlbumTint::css(null));
        $this->assertNull(AlbumTint::css(['src' => 'x', 'h' => null, 's' => null]));
    }

    public function test_the_accent_colour_is_readable_and_never_muddy(): void
    {
        $this->assertSame('hsl(8 85% 62%)', AlbumTint::accent(['h' => 8, 's' => 85]));
        $this->assertSame('hsl(220 45% 62%)', AlbumTint::accent(['h' => 220, 's' => 20]), 'a pale cover still gets a clear colour');
        $this->assertSame('hsl(120 90% 62%)', AlbumTint::accent(['h' => 120, 's' => 100]), 'and a neon one is held back');
        $this->assertSame('hsl(0 0% 88%)', AlbumTint::accent(['h' => 0, 's' => 0]), 'black and white covers get a soft white');
        $this->assertNull(AlbumTint::accent(null));
        $this->assertNull(AlbumTint::accent(['src' => 'x', 'h' => null, 's' => null]));
    }

    public function test_the_party_screen_shows_the_covers_colour_on_its_bar_and_tells_the_page_when_it_changes(): void
    {
        $room = $this->room(['now_playing_track_id' => 't1', 'now_playing_name' => 'Song', 'now_playing_artist' => 'Band', 'now_playing_duration_ms' => 100000,
            'now_playing_tint' => ['src' => 'x', 'h' => 8, 's' => 85], 'is_playing' => true, 'now_playing_started_at' => now()]);

        Livewire::test(PartyScreen::class, ['room' => $room])->assertDispatched('party-accent', color: 'hsl(8 85% 62%)');

        $page = $this->get(route('rooms.party', $room))->getContent();
        $this->assertStringContainsString('hsl(8 85% 62%)', $page);
        $this->assertStringContainsString('party-accent-bg', $page);
        $this->assertStringContainsString('style="--party-accent: hsl(8 85% 62%)"', $page, 'in the first paint, so it does not start green and ease over');
    }

    public function test_nothing_on_the_party_screen_is_left_in_the_fixed_green(): void
    {
        $room = $this->room(['now_playing_tint' => ['src' => 'x', 'h' => 8, 's' => 85]]);

        $html = $this->get(route('rooms.party', $room))->getContent();

        // Only the bar, labels, the join button and its popup follow the cover; the album tile's frame is a neutral border.
        preg_match_all('/class="[^"]*\btext-aux-accent\b[^"]*"/', $html, $matches);
        // The big icon of the "You're offline" overlay is hidden until the connection drops.
        $visible = array_values(array_filter($matches[0], fn (string $class) => ! str_contains($class, 'w-14 h-14')));
        $this->assertSame([], $visible, 'a fixed-green accent next to the cover-coloured ones looks like a mistake');
    }

    public function test_it_works_out_a_rooms_tint_once_per_picture(): void
    {
        Cache::flush();
        Http::fake(['i.scdn.co/*' => Http::response($this->png([[220, 20, 20]]))]);
        $room = $this->room(['now_playing_album_art_url' => self::ART]);

        $this->assertTrue(app(AlbumTint::class)->ensure($room));
        $this->assertSame(0, $room->fresh()->now_playing_tint['h']);
        $this->assertSame(sha1(self::ART), $room->fresh()->now_playing_tint['src']);

        $this->assertFalse(app(AlbumTint::class)->ensure($room->fresh()), 'already done for this picture');
        Http::assertSentCount(1);

        // The same picture on another room, or later, comes from the cache.
        $other = $this->room(['invite_code' => 'DDDDDD-EEEEEE-FFFFFF', 'now_playing_album_art_url' => self::ART]);
        app(AlbumTint::class)->ensure($other);
        Http::assertSentCount(1);
    }

    public function test_a_new_picture_gets_a_new_tint_and_no_art_clears_it(): void
    {
        Cache::flush();
        Http::fake([
            'i.scdn.co/image/abc123' => Http::response($this->png([[220, 20, 20]])),
            'i.scdn.co/image/other' => Http::response($this->png([[20, 40, 230]])),
        ]);
        $room = $this->room(['now_playing_album_art_url' => self::ART]);
        app(AlbumTint::class)->ensure($room);

        $room->update(['now_playing_album_art_url' => 'https://i.scdn.co/image/other']);
        $this->assertTrue(app(AlbumTint::class)->ensure($room));
        $this->assertEqualsWithDelta(230, $room->fresh()->now_playing_tint['h'], 10);

        $room->update(['now_playing_album_art_url' => null]);
        $this->assertTrue(app(AlbumTint::class)->ensure($room));
        $this->assertNull($room->fresh()->now_playing_tint);
    }

    public function test_only_spotifys_image_hosts_are_fetched(): void
    {
        Cache::flush();
        Http::fake();
        $room = $this->room(['now_playing_album_art_url' => 'https://evil.example.com/cover.jpg']);

        app(AlbumTint::class)->ensure($room);
        foreach (['http://i.scdn.co/image/x', 'https://scdn.co.evil.example/x', 'https://evilscdn.co/x', 'https://127.0.0.1/x'] as $url) {
            $this->assertSame([], app(AlbumTint::class)->fromUrl($url));
        }

        Http::assertNothingSent();
        $this->assertNull(AlbumTint::css($room->fresh()->now_playing_tint));
    }

    public function test_a_failed_download_is_remembered_so_it_is_not_retried_every_second(): void
    {
        Cache::flush();
        Http::fake(['i.scdn.co/*' => Http::response('', 500)]);
        $room = $this->room(['now_playing_album_art_url' => self::ART]);

        app(AlbumTint::class)->ensure($room);
        app(AlbumTint::class)->ensure($room->fresh());

        Http::assertSentCount(1);
        $this->assertNull(AlbumTint::css($room->fresh()->now_playing_tint));
    }

    public function test_a_track_change_stores_the_tint_and_tells_screens_after_the_playback_update(): void
    {
        Cache::flush();
        Event::fake([RoomUpdated::class]);
        Http::fake(['i.scdn.co/*' => Http::response($this->png([[20, 200, 40]]))]);
        $room = $this->room();

        app(PlaybackSync::class)->applyState($room, [
            'is_playing' => true, 'progress_ms' => 0, 'track_id' => 't1', 'device_id' => 'd', 'name' => 'Song', 'artist' => 'Band',
            'artists' => [], 'explicit' => false, 'album_art_url' => self::ART, 'duration_ms' => 100000, 'context_uri' => null,
            'shuffle_enabled' => false, 'smart_shuffle_enabled' => false, 'supports_volume' => true, 'volume_percent' => 50, 'repeat_mode' => 'off',
        ]);

        $this->assertEqualsWithDelta(125, $room->fresh()->now_playing_tint['h'], 12);

        $reasons = Event::dispatched(RoomUpdated::class)->map(fn (array $args) => $args[0]->reason)->values()->all();
        $this->assertSame(['playback', 'tint'], $reasons, 'the track change goes out before the colour is worked out');
    }

    public function test_the_tint_is_made_even_when_the_track_was_already_shown_by_a_skip_prediction(): void
    {
        Cache::flush();
        Http::fake(['i.scdn.co/*' => Http::response($this->png([[20, 40, 230]]))]);
        $room = $this->room([
            'now_playing_track_id' => 't1', 'now_playing_album_art_url' => self::ART, 'is_playing' => true, 'now_playing_position_ms' => 0,
            'now_playing_started_at' => now(), 'volume_percent' => 50, 'volume_supported' => true, 'repeat_mode' => 'off',
        ]);

        // Nothing differs from what the room shows, which is the early return in applyState().
        app(PlaybackSync::class)->applyState($room, [
            'is_playing' => true, 'progress_ms' => 0, 'track_id' => 't1', 'device_id' => 'd', 'name' => 'Song', 'artist' => 'Band',
            'artists' => [], 'explicit' => false, 'album_art_url' => self::ART, 'duration_ms' => 100000, 'context_uri' => null,
            'shuffle_enabled' => false, 'smart_shuffle_enabled' => false, 'supports_volume' => true, 'volume_percent' => 50, 'repeat_mode' => 'off',
        ]);

        $this->assertEqualsWithDelta(230, $room->fresh()->now_playing_tint['h'], 10);
    }

    public function test_the_party_screen_sends_the_colour_to_the_aurora(): void
    {
        Cache::flush();
        Http::fake(['i.scdn.co/*' => Http::response($this->png([[220, 20, 20]]))]);
        $room = $this->room(['now_playing_album_art_url' => self::ART]);
        app(AlbumTint::class)->ensure($room);
        $css = AlbumTint::css($room->fresh()->now_playing_tint);

        Livewire::test(PartyScreen::class, ['room' => $room->fresh()])->assertDispatched('aurora-tint', hue: $css['hue'], sat: $css['sat']);

        $page = $this->get(route('rooms.party', $room))->getContent();
        $this->assertStringContainsString('is-tinted', $page);
        $this->assertStringContainsString("--tint-hue: {$css['hue']}deg", $page);
    }

    public function test_other_pages_keep_the_plain_green_aurora(): void
    {
        $page = $this->get('/')->getContent();

        $this->assertStringContainsString('aurora-parallax', $page);
        $this->assertStringNotContainsString('is-tinted', str_replace("classList.add('is-tinted')", '', str_replace("classList.remove('is-tinted')", '', $page)));
    }
}
