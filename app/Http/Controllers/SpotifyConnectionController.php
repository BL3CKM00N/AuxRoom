<?php

namespace App\Http\Controllers;

use App\Models\SpotifyAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SpotifyConnectionController extends Controller
{
    private const SCOPES = 'user-read-email user-read-private user-read-playback-state user-modify-playback-state user-read-currently-playing';

    /**
     * Store the user's own Spotify Developer app credentials, then kick off
     * the Authorization Code flow against Spotify using those credentials.
     */
    public function connect(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:255'],
        ]);

        $account = SpotifyAccount::updateOrCreate(
            ['user_id' => $request->user()->id],
            $validated,
        );

        $state = Str::random(40);
        $request->session()->put('spotify_oauth_state', $state);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $account->client_id,
            'scope' => self::SCOPES,
            'redirect_uri' => route('spotify.callback'),
            'state' => $state,
        ]);

        return redirect()->away('https://accounts.spotify.com/authorize?'.$query);
    }

    public function callback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull('spotify_oauth_state');

        if ($request->query('error')) {
            return redirect()->route('dashboard')->with('error', 'Spotify connection was cancelled: '.$request->query('error'));
        }

        if (! $expectedState || $request->query('state') !== $expectedState) {
            return redirect()->route('dashboard')->with('error', 'Spotify connection could not be verified. Please try again.');
        }

        $account = $request->user()->spotifyAccount;

        if (! $account) {
            return redirect()->route('dashboard')->with('error', 'Add your Spotify app credentials first.');
        }

        $response = Http::asForm()
            ->withBasicAuth($account->client_id, $account->client_secret)
            ->post('https://accounts.spotify.com/api/token', [
                'grant_type' => 'authorization_code',
                'code' => $request->query('code'),
                'redirect_uri' => route('spotify.callback'),
            ]);

        if ($response->failed()) {
            return redirect()->route('dashboard')->with('error', 'Spotify rejected the connection: '.$response->json('error_description', 'unknown error'));
        }

        $data = $response->json();

        $account->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in']),
            'scopes' => $data['scope'],
        ]);

        $profile = Http::withToken($account->access_token)->get('https://api.spotify.com/v1/me');

        if ($profile->successful()) {
            $account->update([
                'spotify_user_id' => $profile->json('id'),
                'display_name' => $profile->json('display_name'),
                'avatar_url' => $profile->json('images.0.url'),
            ]);
        }

        return redirect()->route('dashboard')->with('status', 'Spotify connected successfully.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $request->user()->spotifyAccount?->delete();

        return redirect()->route('dashboard')->with('status', 'Spotify account disconnected.');
    }
}
