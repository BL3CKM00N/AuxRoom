<?php

namespace App\Services\Spotify;

use App\Models\Room;

/**
 * "Check Spotify": the read-only questions behind most Spotify problems, asked
 * one after another and answered in plain words, each with the catalog code of
 * the guide that explains the fix. It only reads (profile, devices, playback
 * state) and never returns a token or a credential.
 */
class SpotifyCheck
{
    public function __construct(private SpotifyClientFactory $clients) {}

    /**
     * @return array<int, array{id: string, label: string, status: 'ok'|'warn'|'fail', detail: string, code: ?string}>
     */
    public function run(Room $room): array
    {
        $account = $room->playbackProvider?->spotifyAccount;

        if ($this->clients->isMock($room) || ! $account) {
            return [$this->row('connection', 'Spotify connection', 'warn', 'No Spotify is connected, so the room runs in demo mode.', 'SP-CONNECT-CREDS')];
        }

        $rows = [];

        $rows[] = $account->client_id && $account->client_secret
            ? $this->row('credentials', 'Spotify app credentials', 'ok', 'A client ID and secret are saved.')
            : $this->row('credentials', 'Spotify app credentials', 'fail', 'No client ID and secret are saved.', 'SP-CONNECT-CREDS');

        if ($account->needsReconnect() || ! $account->access_token) {
            $rows[] = $this->row('connection', 'Spotify login', 'fail', 'Spotify turned the saved login down, or it was never completed.', 'SP-DISCONNECTED');

            return [...$rows, $this->row('rest', 'Everything else', 'warn', 'Skipped until Spotify is reconnected.')];
        }

        $client = $this->clients->forRoom($room);

        $profile = $client->getProfile();
        $rows[] = $profile
            ? $this->row('connection', 'Spotify login', 'ok', 'Connected as '.($profile['display_name'] ?: $profile['id'] ?: 'your account').'.')
            : $this->row('connection', 'Spotify login', 'warn', "Spotify didn't answer. It may be busy, or the login may have just expired. Try again in a moment.", 'SP-CMD');

        if ($profile) {
            $premium = ($profile['product'] ?? null) === 'premium';
            $rows[] = $this->row('premium', 'Premium account', $premium ? 'ok' : 'fail',
                $premium ? 'This account is Premium.' : 'This account is not Premium ('.($profile['product'] ?: 'unknown').'). Spotify blocks remote control for it.',
                $premium ? null : 'SP-PREMIUM');
        }

        $devices = $client->getDevices();
        $active = collect($devices)->firstWhere('is_active', true);

        $rows[] = match (true) {
            $devices === [] => $this->row('devices', 'Playback devices', 'fail', 'Spotify lists no devices. A phone with a sleeping Spotify app does this.', 'SP-ASLEEP'),
            ! $active => $this->row('devices', 'Playback devices', 'warn', count($devices).' listed ('.collect($devices)->pluck('name')->join(', ').'), but none is active. Press play in Spotify on one.', 'SP-ASLEEP'),
            default => $this->row('devices', 'Playback devices', 'ok', count($devices).' listed. Active: '.$active['name'].'.'),
        };

        $chosen = $account->active_device_id;
        if ($chosen && $devices !== [] && ! collect($devices)->contains('id', $chosen)) {
            $rows[] = $this->row('chosen', 'Device chosen in Room Settings', 'warn', ($account->active_device_name ?: 'The chosen device').' is not in the list any more. Pick a listed device in Room Settings.', 'SP-ASLEEP');
        }

        try {
            $state = $client->getPlaybackState();
        } catch (SpotifyRequestFailed) {
            $state = false;
        }

        $rows[] = match (true) {
            $state === false => $this->row('playback', 'Playback', 'warn', "Spotify didn't answer when asked what is playing. Try again in a moment.", 'SP-CMD'),
            $state === null => $this->row('playback', 'Playback', 'warn', 'Spotify reports nothing active, so there is nothing to control until something plays.', 'SP-ASLEEP'),
            default => $this->row('playback', 'Playback', 'ok', ($state['is_playing'] ? 'Playing' : 'Paused').': '.($state['name'] ?? 'unknown track').'.'),
        };

        if (is_array($state) && ! $state['supports_volume']) {
            $rows[] = $this->row('volume', 'Volume control', 'warn', "The active device doesn't accept volume changes from outside.", 'SP-VOLUME');
        }

        return $rows;
    }

    /** @return array{id: string, label: string, status: string, detail: string, code: ?string} */
    private function row(string $id, string $label, string $status, string $detail, ?string $code = null): array
    {
        return compact('id', 'label', 'status', 'detail', 'code');
    }
}
