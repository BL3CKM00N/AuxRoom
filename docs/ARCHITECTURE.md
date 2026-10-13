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
- If the watcher is dead (`WatchState::watcherAlive()`, heartbeat TTL 15s) pages fall back to polling Spotify themselves
  (host dashboard 10s, party screen 15s). `/health/watcher` reports 503 when the heartbeat or Reverb is down.
- The live dot in the room header is client side: websocket state plus the server's `watcher-status` event.
- Reverb behind the proxy: the browser's key comes from a meta tag, host and scheme from the page. The `VITE_REVERB_*` build
  values are unreliable in Nixpacks (they arrived as the literal text `${...}`), so `resources/js/echo.js` treats those as unset.

## Spotify API facts

- `GET /me/player` returns 204 when no device is active. A sleeping iPhone shows as 502 "Bad gateway" on commands.
- The queue cannot be edited: no remove, no reorder, no clear. Everything about the waiting list follows from that.
- Smart Shuffle cannot be turned on through the API (`state=smart` returns 400). It is only readable (`smart_shuffle`, undocumented).
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

## Monitoring

- Uptime Kuma (LAN): the homepage monitor (id 8) exists. Add `/health/watcher` and `/up` monitors (tag Public) with the
  `uptimekuma` skill's `manage.mjs`.

## Working agreements

- Never use em dashes anywhere. Commits as the configured identity. Never print credential values.
- Anything that changes production or an external service needs an explicit "go" first.
- Ship after each step; short summaries unless asked for detail.
