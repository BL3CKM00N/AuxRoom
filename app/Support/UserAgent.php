<?php

namespace App\Support;

/**
 * Just enough of a user-agent parser to label a login as "Safari on iPhone"
 * in the signed-in devices list. It covers the browsers and systems people
 * actually use; anything else reads as "Unknown device" rather than a guess.
 */
final class UserAgent
{
    /** @return array{browser: string, os: string, label: string} */
    public static function parse(?string $agent): array
    {
        $agent = (string) $agent;

        $os = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'CrOS') => 'Chromebook',
            str_contains($agent, 'Macintosh'), str_contains($agent, 'Mac OS X') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown',
        };

        // Order matters: Edge, Opera and Chrome on iOS all also say "Chrome" or "Safari".
        $browser = match (true) {
            str_contains($agent, 'Edg/'), str_contains($agent, 'EdgA/'), str_contains($agent, 'EdgiOS/') => 'Edge',
            str_contains($agent, 'OPR/'), str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Firefox/'), str_contains($agent, 'FxiOS/') => 'Firefox',
            str_contains($agent, 'SamsungBrowser/') => 'Samsung Internet',
            str_contains($agent, 'CriOS/'), str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Unknown',
        };

        $label = match (true) {
            $browser === 'Unknown' && $os === 'Unknown' => 'Unknown device',
            $browser === 'Unknown' => $os,
            $os === 'Unknown' => $browser,
            default => "{$browser} on {$os}",
        };

        return ['browser' => $browser, 'os' => $os, 'label' => $label];
    }
}
