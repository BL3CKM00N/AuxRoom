<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="public/images/auxroom-logo.svg">
    <source media="(prefers-color-scheme: light)" srcset="public/images/auxroom-logo-light.svg">
    <img src="public/images/auxroom-logo-light.svg" alt="AuxRoom" width="280">
  </picture>
</p>

# AuxRoom

A shared-listening-room app for Spotify. One person hosts, everyone else joins with a code (or a link, or a QR code) and can see what's playing, queue up tracks, and vote in on the aux — all in real time, from any browser, no app install required.

## What it does

- **Rooms** — a host opens a room, connects their own Spotify account, and shares an invite code or link. Guests join instantly, no account required.
- **Shared queue** — guests can search Spotify and add tracks to the queue (if the host allows it), browse and play playlists, and see what's coming up next.
- **Real-time sync** — playback state, the queue, and room settings update live for everyone in the room via WebSockets (Laravel Reverb), with an independent polling fallback so nothing goes stale if a broadcast is missed.
- **Host controls** — lock the queue, manage individual guest permissions, kick or approve members, require guests to request access, and close the room.
- **Location enforcement** — hosts can optionally restrict control of the room to guests who are physically nearby, picked visually on a map (Leaflet + OpenStreetMap). Guests are re-verified periodically while the tab is open, and revoked immediately if the host moves or resizes the boundary.
- **Party Screen** — a dedicated, big-screen "now playing" view (album art, progress, up next) meant for a TV or a shared display, independent of the host's own dashboard tab.
- **Bring your own Spotify app** — each host connects using their own Spotify Developer app credentials (client ID/secret), so no shared API quota or central app review is required.

## Tech stack

- [Laravel 13](https://laravel.com/docs) with [Livewire 3](https://livewire.laravel.com/) (+ [Volt](https://livewire.laravel.com/docs/volt) for the navigation component)
- [Laravel Reverb](https://laravel.com/docs/reverb) for WebSocket broadcasting
- Tailwind CSS + Alpine.js
- Leaflet + OpenStreetMap for the location picker
- SQLite for local development, PostgreSQL in production
- Deployed via [Dokploy](https://dokploy.com/) using Nixpacks (see [`nixpacks.toml`](nixpacks.toml))

## Getting started

### Requirements

- PHP 8.4+
- Composer
- Node.js + npm
- A Spotify account and a [Spotify Developer app](https://developer.spotify.com/dashboard) (each host connects their own — see below)

### Setup

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate
```

### Running locally

You'll want three things running side by side:

```bash
php artisan serve
```

```bash
npm run dev
```

```bash
php artisan reverb:start
```

Then visit `http://localhost:8000`.

If Reverb isn't running, the app still works — broadcasts fail gracefully and are logged as warnings rather than breaking the request, and the UI falls back to polling.

### Connecting Spotify

AuxRoom doesn't ship with a shared Spotify app — each host connects their own from **Room Settings**:

1. Create an app at the [Spotify Developer Dashboard](https://developer.spotify.com/dashboard).
2. Add `http://localhost:8000/spotify/callback` (or your production URL's equivalent) as a Redirect URI.
3. Enter that app's Client ID and Client Secret in AuxRoom's Room Settings tab.

Without a connected Spotify account, a room runs in a mock playback mode so the rest of the app (queue, guests, permissions) can still be exercised without real Spotify calls.

## Testing

```bash
php artisan test
```

## License

The Laravel framework this app is built on is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
