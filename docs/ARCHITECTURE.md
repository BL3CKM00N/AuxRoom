# AuxRoom: how it works and what is not obvious

Read this before changing playback, the watcher, the queue, auth or the deploy setup. The code says
what exists; this says why, and what has already bitten us. Keep it short: add a line when something
surprising is learned, delete lines that stop being true.

## Stack and deploy

- Laravel 13, Livewire 3 (Volt for some pages), Alpine, Tailwind, Vite. PostgreSQL in production, SQLite locally.
- Production: https://auxroom.flashba.ng on Dokploy (Nixpacks). Three services in the AuxRoom project:
  Application (`auxroom-application-rqx1zc`), Database (Postgres), Cache (Redis, `auxroom-cache-4kl8vc`).
- The Application container runs three processes (see `nixpacks.toml`): `php artisan serve`, `reverb:start`
  (websockets, port 8080, reached through the Dokploy domain entry `/app`), and `spotify:watch` in a restart loop.
- The web server is PHP's built-in one. With the default (one worker) every request waits for the one before it, so a burst (a track
  change pushes every open screen to re-render at once) was served one at a time. `PHP_CLI_SERVER_WORKERS=6` with `--no-reload` (Laravel
  only honours the variable with that flag) gives six. Check with `ps aux | grep php`.
- Deploy: push to `main`, then deploy the application in Dokploy (skill `dokploy`). Migrations run on start.
- Look at production: `ssh deployment`, then `docker ps | grep auxroom-application` and `docker exec <name> php artisan tinker`.
  Logs go to the container's stderr (`docker logs`), not to a file.
- Local dev: `php artisan serve` on :8000 plus `npm run dev`. Leave both running. The demo room is a mock (no Spotify).

## Realtime and the watcher (the heart of "accurate to the second")

- Spotify has no push. Anything that must be true to the second is read by the **watcher** (`app/Services/Spotify/RoomWatcher.php`,
  command `spotify:watch`), which polls each open room and stores the result. Screens do not ask Spotify; they `touch` the room
  (`WatchState::touch`) so the watcher knows someone is looking, and render what is stored.
- Cadence per room: 0.3s right after a command until Spotify shows its effect, 1s while watched (touched in the last 30s), 5s for
  five minutes after, none otherwise. Backs off on errors and honours Retry-After. Rooms are polled one after another, so many
  watched rooms at once slow each room down.
- Spotify reads lag writes by up to a second. After a command the watcher ignores a read that still shows the old state
  (`WatchState::expect`, `settled()`), which is what stops a skip from "undoing itself".
- Skip shows the next track immediately by predicting it from the queue (`PlaybackPrediction`). Not possible with shuffle,
  repeat-one or at the end of a playlist; those show once the watcher confirms.
- Opening a room that nobody has confirmed lately (a new room, or an unwatched one) makes the page read Spotify once itself
  (`PlaybackSync::refreshIfStale`, guarded by `WatchState::verifiedAge`), so the first render shows the song. A push sent before the page's
  websocket was ready is lost, so `echo.js` announces `realtime-connected` and the room pages re-read once when it comes up.
- How often a room is read depends on what people are doing in it (`Room::activityTier`, `last_activity_at`): active (someone did something in
  the last 15 min) 2 s playing / 4 s paused; quiet (to 1 h) 10 s / 30 s; sleeping (over 1 h) 30 s / 60 s; always 0.3 s around a song's end and
  after a command. "Doing something" is `Room::markActive()`: opening a room, a control (`passesGate`), anything logged, a join. A screen's own
  polling never counts, or a forgotten tab would keep a room awake for ever. Waking a resting room sets `WatchState::wake`, which the watcher
  honours at once (`RoomWatcher::isDue`).
- One Spotify app may send `SpotifyBudget::READS_PER_MINUTE` (120) reads a minute, however many rooms and screens there are; commands are exempt.
  A read that is denied reads as a short 429 ("wait for the next minute"), and a failed read of devices or the queue returns the last known list
  instead of an empty one, so the room never claims "no device" just because it could not ask.
- Positions are kept to the millisecond: `rooms.now_playing_started_ms` (the datetime column only holds whole seconds, which made every
  position up to a second wrong). Setting `now_playing_started_at` fills both through a mutator. `Room::positionAtMs()` and `startedAtMs()`
  use it. Spotify's `progress_ms` is as of halfway through the request, so `PlaybackSync` pins it to that moment (`fetched_at_ms`).
  A difference over 800 ms is a jump (pushed to screens); over 300 ms the start is nudged quietly; below that nothing changes.
- Around a song's end (1.5 s before to 2 s after) the room is read every 0.3 s (`RoomWatcher::aroundTrackEnd`), so the next song shows at once.
  Rooms that are due together are read in parallel (`RoomWatcher::prefetch`, `Http::pool`), and a Spotify login is refreshed by one process
  at a time (`SpotifyTokenManager`, a lock), because a refresh token reused after Spotify rotated it flags the account as disconnected.
- Screens count progress from the server's start time against the server's clock (`resources/js/server-clock.js`, a few pings to `/time`,
  best round trip wins), so every device shows the same position. `?debug=1` on a room address shows the offsets, pushes and drift.
- Pushes: events are broadcast under a custom name, so listeners need a leading dot (`echo:room.X,.RoomUpdated`). Without it nothing ever
  arrived and pages only polled (`EchoEventNamesTest` guards this). Every push carries a per-room number; a page that sees one missing
  re-reads the room, and while the connection is down or the watcher is not running it re-reads every 4 s (`room-guard.js`). The watcher
  also pushes when the queue changed. Echo's ping settings are shortened so a dead connection is noticed in about 20 s.
- If the watcher is dead (`WatchState::watcherAlive()`, heartbeat TTL 15s) pages fall back to polling Spotify themselves
  (host dashboard 10s, party screen 15s). `/health/watcher` reports 503 when the heartbeat or Reverb is down.
- The live dot in the room header is client side: websocket state plus the server's `watcher-status` event.
- Reverb behind the proxy: the browser's key comes from a meta tag, host and scheme from the page. The `VITE_REVERB_*` build
  values are unreliable in Nixpacks (they arrived as the literal text `${...}`), so `resources/js/echo.js` treats those as unset.

## Spotify API facts

- `GET /me/player` returns 204 when no device is active. A sleeping iPhone shows as 502 "Bad gateway" on commands.
- The queue cannot be edited: no remove, no reorder, no clear. Everything about the waiting list follows from that.
- Smart Shuffle cannot be turned on through the API (`state=smart` returns 400). It is only readable (`smart_shuffle`, undocumented).
- The player endpoints (`/me/player`, its queue, devices and every command) have their own strict quota per Spotify *app* (client id), not per
  listener. When it runs out Spotify answers 429 `QUOTA_EXCEEDED` with a `Retry-After` that can be hours (we saw about 12), while `/me` and search
  keep working. `SpotifyBudget` remembers the first 429 per app and every caller stays quiet until it is over; the room says "Spotify is
  limiting AuxRoom" (guide `SP-QUOTA`) instead of "no device found", and `/health/watcher` goes 503 with the time left. A second Spotify app has
  its own quota. Steady polling is deliberately slow (2 s watched and playing, 4 s paused, 10 s recently watched; 0.3 s only around a song's end
  or after a command): one read a second is 86,400 requests a day for as long as a screen is open. `spotify:calls:*` counts requests per hour.
- `supports_volume` is false for some devices; the volume number then means nothing.
- Spotify pads its queue with the playlist wrapped around, repeating the current track. `UpcomingQueue::trim` removes that.
- The API key per room is the host's own Spotify app credentials; tokens refresh on use. Image URLs are on `*.scdn.co`.

## Queue, rules and roles

- A guest's song is a `queue_items` row. `released_at` null means it waits on AuxRoom's list; set means Spotify has it.
  `removed_at` means removed after it was handed over (hidden, skipped when it starts, `skipped_at` marks that done).
  Scopes `released()` and `waiting()`. Anything matching a playing track to a queue item must use `released()`.
- Waiting list (`rooms.waiting_list_enabled`, off by default, needs the watcher): `QueueFeeder` keeps exactly one real song in
  Spotify's queue ahead ("next up", locked). Skip hands one over first. Rotation (`rotation_enabled`) orders waiting songs one
  per guest, whoever went longest ago first. Without the watcher songs go straight to Spotify.
- `QueueRules`: limit per guest (songs waiting), duplicate guard (`played_tracks` history plus playing and waiting), block list,
  explicit filter. Checked on the server in `addToQueue`. Staff get a warning and "Add anyway"; a guest can be exempted.
- Roles on `room_members.role`: `host`, `cohost`, `guest`. `isStaff()` is host or co-host; `canManage` on the component.
  Co-hosts: approve, deny, remove guests, skip and control playback past per-guest permissions and the queue lock, location-exempt.
  Only the host: settings, rules, permissions, promote, close, Spotify account, remove staff. `revokeAllAccess` also removes co-hosts.
- Album-art aurora on the party screen: the server works out the cover's hue once (`AlbumTint`, Spotify image hosts only, after the
  track change is broadcast) and the page rotates the green aurora with a CSS filter. Only `.is-tinted` pays for the filter.
  The party screen's progress bar and labels use the same colour as `--party-accent` (`AlbumTint::accent`, set on the screen's root).

## Cache, sessions, auth

- `CACHE_STORE=failover`: Redis, then the database. `Cache::lock()` only fails when used, so `QrLogin` takes locks from both explicitly.
  The predis client is used (no phpredis in the image). Sessions stay in the database: signed-in devices read the `sessions` table.
- Logout closes a room only when it was the host's last live login. Session lifetime is 2 hours. "Log out on all devices" and the
  signed-in devices list are on the profile page. QR login works both ways (a phone scans a computer's code, or the computer scans),
  with number matching. See `app/Services/Auth/QrLogin.php`.

## Errors and help

- Every user-facing error has a code in `app/Support/Errors/ErrorCatalog.php` (`/help` renders it). `ErrorGuideTest` scans the app
  for raised codes and fails when one is missing from the catalog, so add the entry in the same change. Wording is soft, never blunt.

## Testing

- Mutation habit: after writing a test, break the code on purpose and confirm the test fails.
- `Http::fake` keeps the first matching stub; to override, `Http::swap(new Factory)` first.
- `Livewire::actingAs()` stays signed in for the rest of the test; test the guest before the host.
- Do not name test helpers `run` or `session` (they clash with PHPUnit and Laravel). Escape apostrophes in `assertSee`.
- Test sessions use the array driver; set `session.driver` to `database` for device-list tests.
- The first browser run right after a PHP run can time out; run it again.
- `pint --test` already fails on old files; do not mass-reformat.
- Rate limits must have names (`RateLimiter::for` in `AppServiceProvider`). A numbered `throttle:10,1` counts per visitor across *all* routes
  that use one, so the page's clock pings used up the join form's allowance and guests got a 429 (`ThrottleBucketsTest`). The join limit is
  60 a minute per address so a party on one Wi-Fi fits.
- In a Livewire update request (a refresh or a wire:click) `request()->routeIs()` and `request()->query()` describe Livewire's own
  request, not the page. Anything that polls and depends on the page (the nav) must remember it at mount (`NavigationRefreshTest`).

## Monitoring

- Uptime Kuma (LAN), all tagged Public: the homepage (id 8), `/health/watcher` (id 20, 503 when the watcher or Reverb is down) and
  `/up` (id 21). Created with the `uptimekuma` skill's `manage.mjs`. The watcher monitor has not been seen going red.

## Working agreements

- Never use em dashes anywhere. Commits as the configured identity. Never print credential values.
- Anything that changes production or an external service needs an explicit "go" first.
- Ship after each step; short summaries unless asked for detail.
