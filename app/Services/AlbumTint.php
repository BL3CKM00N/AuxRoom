<?php

namespace App\Services;

use App\Models\Room;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The colour the party screen's aurora takes from the album art: the art's most vivid hue and
 * how vivid it is. Worked out once per picture (on the server, so every screen shows the same
 * colour) from a 24 by 24 copy of it, and remembered for a week.
 */
class AlbumTint
{
    /** Only Spotify's own image hosts are fetched, whatever URL a response contains. */
    private const HOSTS = ['scdn.co', 'spotifycdn.com'];

    private const SIZE = 24;

    private const CACHE_SECONDS = 604800;

    /** The aurora pictures are green at about this hue; the tint rotates from there. */
    private const AURORA_HUE = 153;

    /**
     * What the aurora's CSS filter needs for a room's tint, or null for the plain green aurora.
     *
     * @return array{hue: int, sat: float}|null
     */
    public static function css(?array $tint): ?array
    {
        if (! $tint || ! isset($tint['h'], $tint['s'])) {
            return null;
        }

        return [
            'hue' => (($tint['h'] - self::AURORA_HUE + 540) % 360) - 180,
            'sat' => round(max(0.3, min(1.3, 0.3 + $tint['s'] / 100)), 2),
        ];
    }

    /**
     * The accent colour for the party screen's bar and labels: the cover's hue at a lightness that reads on the
     * dark background, never muddier than 45% saturation. A black and white cover gets a soft white.
     */
    public static function accent(?array $tint): ?string
    {
        if (! $tint || ! isset($tint['h'], $tint['s'])) {
            return null;
        }

        if ($tint['s'] < 12) {
            return 'hsl(0 0% 88%)';
        }

        return sprintf('hsl(%d %d%% 62%%)', $tint['h'], max(45, min(90, $tint['s'])));
    }

    /**
     * Makes sure the room's tint belongs to the album art it is showing. Cheap when it already does.
     * Returns true when the room changed (so callers can tell screens).
     */
    public function ensure(Room $room): bool
    {
        $url = $room->now_playing_album_art_url;

        if (! $url) {
            if ($room->now_playing_tint === null) {
                return false;
            }

            $room->update(['now_playing_tint' => null]);

            return true;
        }

        $src = sha1($url);

        if (($room->now_playing_tint['src'] ?? null) === $src) {
            return false;
        }

        $tint = Cache::remember("album-tint:{$src}", self::CACHE_SECONDS, fn () => $this->fromUrl($url));

        $room->update(['now_playing_tint' => ['src' => $src, 'h' => $tint['h'] ?? null, 's' => $tint['s'] ?? null]]);

        return true;
    }

    /** @return array{h: int, s: int}|array{} empty when the picture could not be used (kept, so it is not retried every second) */
    public function fromUrl(string $url): array
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');

        if (($parts['scheme'] ?? '') !== 'https' || ! collect(self::HOSTS)->contains(fn ($allowed) => $host === $allowed || str_ends_with($host, '.'.$allowed))) {
            return [];
        }

        try {
            $response = Http::timeout(3)->get($url);
        } catch (Throwable) {
            return [];
        }

        if ($response->failed() || strlen($response->body()) > 2_000_000) {
            return [];
        }

        return $this->fromImageData($response->body());
    }

    /** @return array{h: int, s: int}|array{} */
    public function fromImageData(string $data): array
    {
        $image = @imagecreatefromstring($data);

        if ($image === false) {
            return [];
        }

        $small = imagescale($image, self::SIZE, self::SIZE);

        if ($small === false) {
            return [];
        }

        $x = $y = $weightSum = $satSum = 0.0;

        for ($px = 0; $px < self::SIZE; $px++) {
            for ($py = 0; $py < self::SIZE; $py++) {
                $color = imagecolorat($small, $px, $py);
                $r = (($color >> 16) & 255) / 255;
                $g = (($color >> 8) & 255) / 255;
                $b = ($color & 255) / 255;

                $max = max($r, $g, $b);
                $delta = $max - min($r, $g, $b);

                // Near-black and near-gray pixels have no real hue and would drag the average around.
                if ($max < 0.15 || $delta < 0.05) {
                    continue;
                }

                $hue = match (true) {
                    $max === $r => fmod(($g - $b) / $delta, 6),
                    $max === $g => ($b - $r) / $delta + 2,
                    default => ($r - $g) / $delta + 4,
                } * 60;

                // Vivid, bright pixels count most.
                $weight = $delta * $max;
                $x += cos(deg2rad($hue)) * $weight;
                $y += sin(deg2rad($hue)) * $weight;
                $satSum += ($delta / $max) * $weight;
                $weightSum += $weight;
            }
        }

        // A black and white (or all dark) cover: no hue, so no tint, and the aurora goes gray.
        if ($weightSum < 0.5) {
            return ['h' => 0, 's' => 0];
        }

        return [
            'h' => (int) round(fmod(rad2deg(atan2($y, $x)) + 360, 360)) % 360,
            's' => (int) round(min(1, $satSum / $weightSum) * 100),
        ];
    }
}
