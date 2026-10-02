<?php

namespace App\Services;

use GdImage;
use Illuminate\Support\Facades\Cache;

/**
 * The picture on an invite link's preview card: "{Host} invited you to their
 * room", in the app's look. Drawn with GD in two shapes (wide for most
 * platforms, square for Snapchat). Contains only the host's name, never the
 * invite code. Cached per name and shape, so crawlers cost nothing after the
 * first.
 */
class ShareImage
{
    public const SHAPES = ['wide' => [1200, 630], 'square' => [1200, 1200]];

    private const FONT_BOLD = 'resources/fonts/Figtree-Bold.ttf';

    private const FONT_SEMIBOLD = 'resources/fonts/Figtree-SemiBold.ttf';

    private const CACHE_SECONDS = 86400;

    public function jpeg(string $shape, string $hostName): string
    {
        $name = self::drawableName($hostName);

        return base64_decode(Cache::remember(
            'share-image:'.$shape.':'.md5($name),
            self::CACHE_SECONDS,
            fn () => base64_encode($this->render($shape, $name)),
        ));
    }

    /**
     * The font only has Latin glyphs; anything else (emoji, other scripts)
     * would draw as empty boxes, so it is dropped rather than shown.
     */
    public static function drawableName(string $name): string
    {
        $clean = preg_replace('/[^\p{Latin}\p{N}\p{P}\p{Zs}]/u', '', $name) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');

        return $clean !== '' ? $clean : 'Someone';
    }

    private function render(string $shape, string $host): string
    {
        [$w, $h] = self::SHAPES[$shape];
        $img = imagecreatetruecolor($w, $h);
        imageantialias($img, true);

        imagefill($img, 0, 0, imagecolorallocate($img, 10, 13, 18));
        $this->glow($img, (int) ($w * 0.82), (int) ($h * 0.12), (int) ($w * 0.62));

        $square = $shape === 'square';
        $left = $square ? 110 : 100;
        $maxWidth = $w - 2 * $left;
        $top = $square ? 150 : 84;

        $this->logo($img, $left, $top, $square ? 84 : 64);

        $white = imagecolorallocate($img, 255, 255, 255);
        $soft = imagecolorallocate($img, 209, 213, 219);
        $muted = imagecolorallocate($img, 156, 163, 175);
        $green = imagecolorallocate($img, 34, 197, 94);

        $text = fn (int $size, int $x, int $y, $color, string $font, string $s) => imagettftext($img, $size, 0, $x, $y, $color, base_path($font), $s);

        // Wordmark beside the mark.
        $text($square ? 46 : 36, $left + ($square ? 110 : 88), $top + ($square ? 62 : 48), $white, self::FONT_BOLD, 'AuxRoom');

        // The host's name, as large as fits on one line.
        $size = $square ? 120 : 92;
        $hostLine = $host;

        while ($size > 40 && $this->width($size, self::FONT_BOLD, $hostLine) > $maxWidth) {
            $size -= 4;
        }

        while (mb_strlen($hostLine) > 3 && $this->width($size, self::FONT_BOLD, $hostLine) > $maxWidth) {
            $hostLine = rtrim(mb_substr($hostLine, 0, -2)).'…';
        }

        $y = $square ? 640 : 340;
        $text($size, $left, $y, $white, self::FONT_BOLD, $hostLine);
        $text($square ? 64 : 50, $left, $y + ($square ? 104 : 80), $green, self::FONT_SEMIBOLD, 'invited you to their room');

        $text($square ? 38 : 30, $left, $square ? 1010 : 548, $muted, self::FONT_SEMIBOLD, 'Add songs to the queue together.');
        $text($square ? 38 : 30, $left, ($square ? 1010 : 548) + ($square ? 60 : 46), $soft, self::FONT_SEMIBOLD, 'No Spotify account needed to join.');

        ob_start();
        imagejpeg($img, null, 86);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }

    private function width(int $size, string $font, string $text): int
    {
        $box = imagettfbbox($size, 0, base_path($font), $text);

        return $box ? abs($box[2] - $box[0]) : 0;
    }

    /** A soft green light, built from many faint concentric discs. */
    private function glow(GdImage $img, int $cx, int $cy, int $radius): void
    {
        imagealphablending($img, true);

        for ($i = 0; $i < 60; $i++) {
            $r = (int) ($radius * (1 - $i / 60));
            $alpha = 127 - (int) (1.6 + $i * 0.07);
            imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, imagecolorallocatealpha($img, 20, 130, 70, max(0, $alpha)));
        }
    }

    /** The app mark: a green rounded square holding three level bars. */
    private function logo(GdImage $img, int $x, int $y, int $size): void
    {
        $green = imagecolorallocate($img, 34, 197, 94);
        $dark = imagecolorallocate($img, 10, 13, 18);
        $radius = (int) ($size * 0.28);

        imagefilledrectangle($img, $x + $radius, $y, $x + $size - $radius, $y + $size, $green);
        imagefilledrectangle($img, $x, $y + $radius, $x + $size, $y + $size - $radius, $green);

        foreach ([[$x + $radius, $y + $radius], [$x + $size - $radius, $y + $radius], [$x + $radius, $y + $size - $radius], [$x + $size - $radius, $y + $size - $radius]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, $radius * 2, $radius * 2, $green);
        }

        $bar = (int) ($size * 0.11);
        $base = $y + (int) ($size * 0.76);

        foreach ([[0.26, 0.34], [0.44, 0.52], [0.62, 0.28]] as [$offset, $height]) {
            $bx = $x + (int) ($size * $offset);
            imagefilledrectangle($img, $bx, $base - (int) ($size * $height) - (int) ($size * 0.2), $bx + $bar, $base, $dark);
        }
    }
}
