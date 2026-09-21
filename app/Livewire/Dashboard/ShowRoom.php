<?php

namespace App\Livewire\Dashboard;

use App\Events\RoomUpdated;
use App\Livewire\Actions\Logout;
use App\Models\ActivityEvent;
use App\Models\QueueItem;
use App\Models\Room;
use App\Models\RoomMember;
use App\Services\RoomMembership;
use App\Services\Spotify\SpotifyClientFactory;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class ShowRoom extends Component
{
    public Room $room;

    public int $memberId;

    public string $search = '';

    /** @var array<int, array> */
    public array $searchResults = [];

    public string $playlistQuery = '';

    /** @var array<int, array> */
    public array $playlistResults = [];

    public string $controlError = '';

    #[Url(as: 'tab')]
    public string $activeTab = 'hub';

    public string $editRoomName = '';

    public int $editRadius = 250;

    public ?string $confirmAction = null;

    /** @var array<int, mixed> */
    public array $confirmParams = [];

    public string $confirmMessage = '';

    public string $confirmLabel = 'Confirm';

    public bool $confirmDanger = true;

    public function mount(RoomMembership $membership, ?Room $room = null): void
    {
        if (! $room) {
            $room = request()->user()?->activeHostedRoom();

            if (! $room) {
                $this->redirect(route('rooms.create'));

                return;
            }
        }

        $this->room = $room;

        if ($room->closed_at) {
            abort(404);
        }

        $found = $membership->resolve($room, request());

        if (! $found) {
            if (request()->user() && request()->user()->id === $room->host_id) {
                $found = $membership->joinAsHost($room);
            } else {
                $this->redirect(route('join', ['code' => $room->invite_code]));

                return;
            }
        }

        $found->update(['last_seen_at' => now()]);
        $this->memberId = $found->id;
        $this->editRoomName = $room->name;
        $this->editRadius = $room->location_radius_m ?? 250;

        if (! request()->has('tab')) {
            $this->activeTab = $found->isHost() ? 'hub' : 'queue';
        }
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function saveRoomDetails(): void
    {
        if (! $this->isHost) {
            return;
        }

        $validated = $this->validate([
            'editRoomName' => ['required', 'string', 'max:255'],
            'editRadius' => ['required', 'integer', 'min:10', 'max:5000'],
        ]);

        $nameChanged = $validated['editRoomName'] !== $this->room->name;
        $radiusChanged = $validated['editRadius'] !== $this->room->location_radius_m;

        $this->room->update([
            'name' => $validated['editRoomName'],
            'location_radius_m' => $validated['editRadius'],
        ]);

        if ($nameChanged) {
            $this->logActivity('settings', "Room renamed to \"{$validated['editRoomName']}\".");
        }

        if ($radiusChanged) {
            $this->logActivity('settings', "Location radius set to {$validated['editRadius']}m.");
        }

        $this->broadcastUpdate('settings');
    }

    /**
     * Ask the user to confirm a destructive action via the in-app modal,
     * instead of the browser's native confirm() dialog.
     */
    public function requestConfirm(string $action, array $params = [], string $message = '', string $label = 'Confirm', bool $danger = true): void
    {
        $this->confirmAction = $action;
        $this->confirmParams = $params;
        $this->confirmMessage = $message;
        $this->confirmLabel = $label;
        $this->confirmDanger = $danger;
    }

    public function confirmYes(): mixed
    {
        $action = $this->confirmAction;
        $params = $this->confirmParams;

        $this->confirmAction = null;
        $this->confirmParams = [];
        $this->confirmMessage = '';

        if ($action && method_exists($this, $action)) {
            return $this->{$action}(...$params);
        }

        return null;
    }

    public function confirmNo(): void
    {
        $this->confirmAction = null;
        $this->confirmParams = [];
        $this->confirmMessage = '';
    }

    #[On('echo:room.{room.invite_code},RoomUpdated')]
    public function onRoomUpdated(): mixed
    {
        return $this->redirectIfRemoved();
    }

    public function heartbeat(): mixed
    {
        if ($redirect = $this->redirectIfRemoved()) {
            return $redirect;
        }

        $this->member->update(['last_seen_at' => now()]);

        // Track transitions are detected by polling Spotify's real state
        // (syncWithSpotify) rather than guessing from a local wall-clock
        // timer — a locally-computed "track probably ended" guess would
        // race with Spotify's own native queue advancing on its own,
        // restarting an already-correctly-playing track from position 0.
        if ($this->isHost) {
            $this->syncWithSpotify();
        }

        return null;
    }

    /**
     * Detects playback changes made outside AuxRoom — pausing, seeking, or
     * skipping from the Spotify app itself, or any other Spotify Connect
     * client — and pulls the room's state back in line with reality. Only
     * the host polls this, since it's the host's Spotify account being
     * queried and every viewer's heartbeat would otherwise multiply calls.
     */
    private function syncWithSpotify(): void
    {
        if ($this->isMock || ! $this->room->playbackProvider?->hasSpotifyConnected()) {
            return;
        }

        $state = app(SpotifyClientFactory::class)->forRoom($this->room)->getPlaybackState();

        if (! $state) {
            // Nothing playing anywhere on the account at all (not even
            // paused) — reflect that if the room still thought otherwise.
            if ($this->room->is_playing) {
                $this->room->update(['is_playing' => false]);
                $this->broadcastUpdate('playback');
            }

            return;
        }

        // Spotify's own context tells us, authoritatively, whether it's
        // currently playing from the selected playlist — rather than
        // trusting a locally-toggled flag that can go stale once native
        // queue items start interleaving with the underlying context.
        $isFallbackContext = $this->room->fallback_playlist_uri
            && $state['context_uri'] === $this->room->fallback_playlist_uri;

        $trackChanged = $state['track_id'] !== $this->room->now_playing_track_id;
        $queueItemId = $this->room->now_playing_queue_item_id;

        if ($trackChanged) {
            // Look up whether a guest explicitly queued this, purely for
            // "added by" attribution — Spotify's own queue/context is the
            // source of truth for order and content, never reconstructed
            // locally (that's what caused the queue to go backwards before).
            $matched = $state['track_id']
                ? $this->room->queueItems()->where('spotify_track_id', $state['track_id'])->whereNull('played_at')->first()
                : null;

            $matched?->update(['played_at' => now()]);
            $queueItemId = $matched?->id;

            if (! $isFallbackContext && ! $matched && $state['track_id']) {
                $this->logActivity('played', "Now playing from Spotify: \"{$state['name']}\".");
            }
        }

        $drifted = abs($state['progress_ms'] - $this->room->currentPositionMs()) > 3000;
        $playStateChanged = $state['is_playing'] !== $this->room->is_playing;
        $fallbackFlagChanged = $isFallbackContext !== $this->room->is_playing_fallback;
        $shuffleChanged = $state['shuffle_enabled'] !== $this->room->shuffle_enabled;
        $repeatChanged = $state['repeat_mode'] !== $this->room->repeat_mode;

        if (! $drifted && ! $playStateChanged && ! $fallbackFlagChanged && ! $trackChanged && ! $shuffleChanged && ! $repeatChanged) {
            return;
        }

        $this->room->update([
            'now_playing_queue_item_id' => $queueItemId,
            'now_playing_track_id' => $state['track_id'],
            'now_playing_name' => $state['name'],
            'now_playing_artist' => $state['artist'],
            'now_playing_album_art_url' => $state['album_art_url'],
            'now_playing_duration_ms' => $state['duration_ms'],
            'is_playing_fallback' => $isFallbackContext,
            'is_playing' => $state['is_playing'],
            'now_playing_position_ms' => $state['progress_ms'],
            'now_playing_started_at' => $state['is_playing'] ? now()->subMilliseconds($state['progress_ms']) : null,
            'shuffle_enabled' => $state['shuffle_enabled'],
            'repeat_mode' => $state['repeat_mode'],
        ]);

        $this->broadcastUpdate('playback');
    }

    /**
     * If the host removed this visitor since their last request, bounce them
     * out immediately instead of leaving them stranded until a manual refresh.
     */
    private function redirectIfRemoved(): mixed
    {
        if (Room::whereKey($this->room->id)->whereNotNull('closed_at')->exists()) {
            session()->flash('status', 'This room has been closed.');

            return $this->redirect($this->isHost ? route('dashboard') : route('join'));
        }

        if ($this->member->left_at !== null) {
            session()->flash('status', 'You were removed from this room.');

            return $this->redirect(route('join', ['code' => $this->room->invite_code]));
        }

        return null;
    }

    public function getMemberProperty(): RoomMember
    {
        return RoomMember::findOrFail($this->memberId);
    }

    public function getIsHostProperty(): bool
    {
        return $this->member->isHost();
    }

    public function getMembersProperty()
    {
        return $this->room->activeMembers()
            ->where(fn ($q) => $q->where('role', 'host')->orWhereNotNull('approved_at'))
            ->with('user.spotifyAccount')
            ->orderByDesc('role')
            ->orderBy('id')
            ->get();
    }

    public function getPendingMembersProperty()
    {
        return $this->room->pendingMembers()->orderBy('id')->get();
    }

    public function getIsApprovedProperty(): bool
    {
        return $this->member->isApproved();
    }

    /** Maps the ability keys used throughout the app to their per-guest column. */
    private const ABILITY_COLUMNS = [
        'guests_can_play_pause' => 'can_play_pause',
        'guests_can_skip' => 'can_skip',
        'guests_can_seek' => 'can_seek',
        'guests_can_set_volume' => 'can_set_volume',
        'guests_can_manage_playlist' => 'can_manage_playlist',
        'guests_can_view_activity' => 'can_view_activity',
    ];

    /**
     * Whether the current visitor is allowed to use a given control — used to
     * visually gray out buttons for guests, mirroring the server-side gate.
     * Add-to-queue stays a single room-wide switch (it's what the emergency
     * stop needs: one instant lock for everyone). Everything else is per-guest.
     */
    public function canGuest(string $ability): bool
    {
        if ($this->isHost) {
            return true;
        }

        // Mirrors passesGate()'s location check — controls otherwise looked
        // enabled for an out-of-range guest and only failed silently on click.
        if (! $this->member->passesLocationCheck()) {
            return false;
        }

        if ($ability === 'guests_can_add_to_queue') {
            return (bool) $this->room->guests_can_add_to_queue;
        }

        return (bool) $this->member->{self::ABILITY_COLUMNS[$ability]};
    }

    public function getOnlineCountProperty(): int
    {
        return $this->members->filter(fn (RoomMember $m) => $m->isOnline())->count();
    }

    /**
     * "Up Next" is Spotify's own live queue, not a local reconstruction —
     * rebuilding it from locally-tracked positions is what caused it to
     * play backwards once Previous/Next could move either direction.
     * Attribution ("added by") is looked up per track, but never used to
     * decide order or content.
     */
    /**
     * Everything Spotify reports as coming up — both guest-queued tracks and
     * upcoming tracks from a playing playlist's shuffle, tagged so the two
     * can be told apart (is_queued). Playlist tracks were never "added" by
     * anyone, so they shouldn't count toward queue counts or attribution.
     */
    public function getQueueProperty()
    {
        if ($this->isMock || ! $this->room->playbackProvider?->hasSpotifyConnected()) {
            return collect();
        }

        $upcoming = app(SpotifyClientFactory::class)->forRoom($this->room)->getQueue();

        return collect($upcoming)->map(function (array $track) {
            $queued = $this->room->queueItems()
                ->where('spotify_track_id', $track['id'])
                ->whereNull('played_at')
                ->with('addedBy')
                ->first();

            return (object) [
                'spotify_track_id' => $track['id'],
                'name' => $track['name'],
                'artist' => $track['artist'],
                'album_art_url' => $track['album_art_url'],
                'duration_ms' => $track['duration_ms'],
                'added_by_name' => $queued?->addedBy?->display_name,
                'is_queued' => $queued !== null,
            ];
        });
    }

    /** The subset of "Up Next" that was actually queued by someone — not upcoming playlist tracks. */
    public function getQueuedCountProperty(): int
    {
        return $this->queue->where('is_queued', true)->count();
    }

    public function getActivityProperty()
    {
        return $this->room->activityEvents()->latest()->limit(30)->get();
    }

    public function getNowPlayingProperty(): ?object
    {
        return $this->room->nowPlayingDetails();
    }

    public function getCurrentPositionMsProperty(): int
    {
        return $this->room->currentPositionMs();
    }

    public function getIsMockProperty(): bool
    {
        return app(SpotifyClientFactory::class)->isMock($this->room);
    }

    public function getEligibleProvidersProperty()
    {
        return $this->room->members()
            ->whereNotNull('user_id')
            ->whereHas('user.spotifyAccount', fn ($q) => $q->whereNotNull('access_token'))
            ->with('user.spotifyAccount')
            ->get()
            ->unique('user_id');
    }

    public function getDevicesProperty(): array
    {
        if ($this->isMock) {
            return app(SpotifyClientFactory::class)->forRoom($this->room)->getDevices();
        }

        if (! $this->room->playbackProvider?->hasSpotifyConnected()) {
            return [];
        }

        return app(SpotifyClientFactory::class)->forRoom($this->room)->getDevices();
    }

    public function updatedSearch(): void
    {
        if (trim($this->search) !== '') {
            $this->activeTab = 'queue';
        }

        $this->search();
    }

    public function search(): void
    {
        $this->controlError = '';

        if (trim($this->search) === '') {
            $this->searchResults = [];

            return;
        }

        $this->searchResults = app(SpotifyClientFactory::class)->forRoom($this->room)->search($this->search, 8);
    }

    public function addToQueue(int $index): void
    {
        if (! $this->passesGate('guests_can_add_to_queue')) {
            return;
        }

        $track = $this->searchResults[$index] ?? null;

        if (! $track) {
            return;
        }

        $nextPosition = (int) ($this->room->queueItems()->max('position') ?? 0) + 1;

        $item = $this->room->queueItems()->create([
            'added_by_id' => $this->member->id,
            'spotify_track_id' => $track['id'],
            'name' => $track['name'],
            'artist' => $track['artist'],
            'album_art_url' => $track['album_art_url'],
            'duration_ms' => $track['duration_ms'],
            'position' => $nextPosition,
        ]);

        $this->logActivity('queued', "{$this->member->display_name} added \"{$item->name}\" to the queue.");

        if (! $this->room->now_playing_track_id && ! $this->room->is_playing) {
            // Nothing playing at all yet — this is the first song, so start it.
            $this->startPlayback($item);
        } else {
            // Something's already playing (a queued track or the fallback
            // playlist) — queue this one to play next without interrupting
            // it, the same as swiping right on a song in Spotify itself.
            $client = app(SpotifyClientFactory::class)->forRoom($this->room);

            if (! $client->addToPlaybackQueue('spotify:track:'.$item->spotify_track_id, $this->providerDeviceId())) {
                $this->controlError = "Spotify couldn't queue that song. Try reselecting the device in Host Hub.";
            }
        }

        $this->broadcastUpdate('queue');

        $this->search = '';
        $this->searchResults = [];
    }

    public function updatedPlaylistQuery(): void
    {
        $this->searchPlaylists();
    }

    public function searchPlaylists(): void
    {
        if (! $this->passesGate('guests_can_manage_playlist')) {
            $this->playlistResults = [];

            return;
        }

        $query = trim($this->playlistQuery);

        if ($query === '') {
            $this->playlistResults = [];

            return;
        }

        $client = app(SpotifyClientFactory::class)->forRoom($this->room);

        // A pasted playlist link resolves directly instead of going through search.
        if ($id = $this->parsePlaylistId($query)) {
            $playlist = $client->getPlaylist($id);
            $this->playlistResults = $playlist ? [$playlist] : [];

            return;
        }

        $this->playlistResults = $client->searchPlaylists($query, 8);
    }

    public function browseMyPlaylists(): void
    {
        if (! $this->passesGate('guests_can_manage_playlist')) {
            return;
        }

        $this->playlistQuery = '';
        $this->playlistResults = app(SpotifyClientFactory::class)->forRoom($this->room)->myPlaylists();
    }

    /**
     * Picks a playlist and starts playing it immediately — the same as
     * tapping a playlist and hitting play in Spotify itself. Queued songs
     * still play next without interrupting it, and it resumes on its own
     * once the queue drains, since we never replace its context to do that.
     */
    public function playPlaylist(int $index): void
    {
        if (! $this->passesGate('guests_can_manage_playlist')) {
            return;
        }

        $playlist = $this->playlistResults[$index] ?? null;

        if (! $playlist) {
            return;
        }

        $this->room->update([
            'fallback_playlist_uri' => $playlist['uri'],
            'fallback_playlist_name' => $playlist['name'],
            'fallback_playlist_image_url' => $playlist['image_url'],
        ]);

        if (! $this->startFallbackPlayback()) {
            return;
        }

        $this->playlistQuery = '';
        $this->playlistResults = [];

        // Learn the actual track Spotify picked to start with right away,
        // instead of waiting up to 3s for the next heartbeat poll.
        $this->syncWithSpotify();

        $this->broadcastUpdate('playback');
    }

    /** Pulls a bare playlist ID out of a pasted Spotify link or URI, if it looks like one. */
    private function parsePlaylistId(string $input): ?string
    {
        if (preg_match('#playlist[/:]([A-Za-z0-9]{10,30})#', trim($input), $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function play(): void
    {
        if (! $this->passesGate('guests_can_play_pause')) {
            return;
        }

        if ($this->room->is_playing) {
            return;
        }

        // A paused device remembers exactly what it was playing — context,
        // queue and position — so resuming bare (no body) picks up exactly
        // where it left off. Re-issuing a track or context here instead
        // (the old approach) replaced the device's native queue every time
        // and, for a shuffled playlist, restarted it from a new random
        // point that looked like the track had skipped ahead.
        if ($this->room->now_playing_track_id) {
            if (app(SpotifyClientFactory::class)->forRoom($this->room)->resume($this->providerDeviceId())) {
                $this->room->update([
                    'is_playing' => true,
                    'now_playing_started_at' => now()->subMilliseconds($this->room->now_playing_position_ms),
                ]);

                $this->logActivity('played', "Playback resumed: \"{$this->room->now_playing_name}\".");
                $this->broadcastUpdate('playback');

                return;
            }

            // Bare resume only fails when Spotify has genuinely dropped the
            // paused state (e.g. after a long gap) — fall back to an
            // explicit restart in that case.
            if ($this->room->is_playing_fallback && $this->room->fallback_playlist_uri) {
                if ($this->startFallbackPlayback()) {
                    $this->syncWithSpotify();
                    $this->broadcastUpdate('playback');
                }

                return;
            }

            if (! $this->playTrackAt(
                $this->room->now_playing_track_id,
                $this->room->now_playing_name ?? 'Unknown track',
                $this->room->now_playing_artist ?? '',
                $this->room->now_playing_album_art_url,
                $this->room->now_playing_duration_ms ?? 0,
                $this->room->now_playing_position_ms,
                $this->room->now_playing_queue_item_id
            )) {
                return;
            }

            $this->logActivity('played', "Playback resumed: \"{$this->room->now_playing_name}\".");
            $this->broadcastUpdate('playback');

            return;
        }

        $next = $this->room->pendingQueueItems()->first();

        if ($next) {
            $this->startPlayback($next);
            $this->broadcastUpdate('playback');

            return;
        }

        if ($this->room->fallback_playlist_uri) {
            if ($this->startFallbackPlayback()) {
                $this->syncWithSpotify();
            }

            $this->broadcastUpdate('playback');
        }
    }

    public function pause(): void
    {
        if (! $this->passesGate('guests_can_play_pause')) {
            return;
        }

        if (! $this->room->is_playing) {
            return;
        }

        $position = $this->room->currentPositionMs();

        if (! app(SpotifyClientFactory::class)->forRoom($this->room)->pause($this->providerDeviceId())) {
            $this->controlError = "Spotify couldn't pause playback.";

            return;
        }

        $this->room->update([
            'is_playing' => false,
            'now_playing_position_ms' => $position,
        ]);

        $this->logActivity('paused', 'Playback paused.');
        $this->broadcastUpdate('playback');
    }

    /**
     * Skips via Spotify's own "next" transport control, not a local guess —
     * this respects whatever Spotify actually has queued/shuffled next,
     * including tracks from a playing playlist we don't know the order of.
     */
    public function skip(): void
    {
        if (! $this->passesGate('guests_can_skip')) {
            return;
        }

        if (! app(SpotifyClientFactory::class)->forRoom($this->room)->skipToNext($this->providerDeviceId())) {
            $this->controlError = "Spotify couldn't skip the track.";

            return;
        }

        $this->logActivity('skipped', "{$this->member->display_name} skipped the track.");
        $this->syncWithSpotify();
        $this->broadcastUpdate('playback');
    }

    public function previous(): void
    {
        if (! $this->passesGate('guests_can_skip')) {
            return;
        }

        if (! app(SpotifyClientFactory::class)->forRoom($this->room)->skipToPrevious($this->providerDeviceId())) {
            $this->controlError = "Spotify couldn't go back a track.";

            return;
        }

        $this->logActivity('skipped', "{$this->member->display_name} went back a track.");
        $this->syncWithSpotify();
        $this->broadcastUpdate('playback');
    }

    public function toggleShuffle(): void
    {
        if (! $this->passesGate('guests_can_play_pause')) {
            return;
        }

        $enabled = ! $this->room->shuffle_enabled;

        if (! app(SpotifyClientFactory::class)->forRoom($this->room)->setShuffle($enabled, $this->providerDeviceId())) {
            $this->controlError = "Spotify couldn't change shuffle.";

            return;
        }

        $this->room->update(['shuffle_enabled' => $enabled]);
        $this->broadcastUpdate('playback');
    }

    public function toggleRepeat(): void
    {
        if (! $this->passesGate('guests_can_play_pause')) {
            return;
        }

        $modes = ['off', 'context', 'track'];
        $next = $modes[(array_search($this->room->repeat_mode, $modes, true) + 1) % count($modes)];

        if (! app(SpotifyClientFactory::class)->forRoom($this->room)->setRepeat($next, $this->providerDeviceId())) {
            $this->controlError = "Spotify couldn't change repeat mode.";

            return;
        }

        $this->room->update(['repeat_mode' => $next]);
        $this->broadcastUpdate('playback');
    }

    public function seek(int $ms): void
    {
        if (! $this->passesGate('guests_can_seek')) {
            return;
        }

        if (! $this->room->now_playing_track_id) {
            return;
        }

        if (! app(SpotifyClientFactory::class)->forRoom($this->room)->seek($ms, $this->providerDeviceId())) {
            $this->controlError = "Spotify couldn't seek playback.";

            return;
        }

        $this->room->update([
            'now_playing_position_ms' => $ms,
            'now_playing_started_at' => $this->room->is_playing ? now()->subMilliseconds($ms) : null,
        ]);

        $this->broadcastUpdate('playback');
    }

    public function setVolume(int $percent): void
    {
        if (! $this->passesGate('guests_can_set_volume')) {
            return;
        }

        $percent = max(0, min(100, $percent));

        if (! app(SpotifyClientFactory::class)->forRoom($this->room)->setVolume($percent, $this->providerDeviceId())) {
            $this->controlError = "Spotify couldn't change the volume.";

            return;
        }

        $this->room->update(['volume_percent' => $percent]);
        $this->broadcastUpdate('playback');
    }

    public function switchProvider(int $userId): void
    {
        if (! $this->isHost) {
            return;
        }

        $this->room->update(['playback_provider_id' => $userId]);
        $this->logActivity('device_changed', 'Playback source switched.');
        $this->broadcastUpdate('settings');
    }

    public function selectDevice(string $deviceId, string $deviceName): void
    {
        if (! $this->isHost) {
            return;
        }

        $this->room->playbackProvider?->spotifyAccount?->update([
            'active_device_id' => $deviceId,
            'active_device_name' => $deviceName,
        ]);

        $this->logActivity('device_changed', "Playback device set to \"{$deviceName}\".");
        $this->broadcastUpdate('settings');
    }

    public function togglePrivate(): void
    {
        if (! $this->isHost) {
            return;
        }

        $isPrivate = ! $this->room->is_private;
        $this->room->update(['is_private' => $isPrivate]);
        $this->logActivity('settings', $isPrivate
            ? 'Room set to private. New guests now need approval to join.'
            : 'Room set to public. Anyone with the code can join instantly.');
        $this->broadcastUpdate('settings');
    }

    /**
     * Set a single guest's permission for a single ability. Permissions are
     * entirely per-guest — there's no room-wide default to fall back to
     * (except add-to-queue, which the emergency stop can still lock for
     * everyone at once).
     */
    public function setMemberPermission(int $memberId, string $ability, bool $allowed): void
    {
        if (! $this->isHost || ! array_key_exists($ability, self::ABILITY_COLUMNS)) {
            return;
        }

        $target = RoomMember::where('room_id', $this->room->id)->where('id', $memberId)->first();

        if (! $target || $target->isHost()) {
            return;
        }

        $column = self::ABILITY_COLUMNS[$ability];
        $target->update([$column => $allowed]);

        $label = str($ability)->after('guests_can_')->replace('_', ' ')->toString();
        $this->logActivity('permission_changed', "{$target->display_name} was ".($allowed ? 'allowed to ' : 'blocked from ')."{$label}.");
        $this->broadcastUpdate('members');
    }

    /**
     * The big red "stop the bleeding" button — instantly blocks every guest,
     * including ones who join later, from adding to the queue, overriding
     * their individual permission. This is the one deliberate exception to
     * "no room-wide switches": an emergency needs a single, instant lock.
     */
    public function emergencyStopQueue(): void
    {
        if (! $this->isHost) {
            return;
        }

        $this->room->update(['guests_can_add_to_queue' => false]);
        $this->logActivity('settings', 'Host locked the queue. No one can add songs right now.');
        $this->broadcastUpdate('settings');
    }

    public function reopenQueue(): void
    {
        if (! $this->isHost) {
            return;
        }

        $this->room->update(['guests_can_add_to_queue' => true]);
        $this->logActivity('settings', 'Host reopened the queue.');
        $this->broadcastUpdate('settings');
    }

    public function toggleLocationEnforced(): void
    {
        if (! $this->isHost) {
            return;
        }

        $enabled = ! $this->room->location_enforced;
        $this->room->update(['location_enforced' => $enabled]);
        $this->logActivity('settings', $enabled
            ? 'Location boundary enforcement turned on.'
            : 'Location boundary enforcement turned off.');
        $this->broadcastUpdate('settings');
    }

    public function setLocationBoundary(float $lat, float $lng, int $radius): void
    {
        if (! $this->isHost) {
            return;
        }

        $this->room->update([
            'location_lat' => $lat,
            'location_lng' => $lng,
            'location_radius_m' => $radius,
        ]);

        $this->logActivity('settings', 'Room location boundary updated.');
        $this->broadcastUpdate('settings');
    }

    public function revokeAllAccess(): void
    {
        if (! $this->isHost) {
            return;
        }

        $this->room->members()->where('role', 'guest')->update(['left_at' => now()]);

        do {
            $code = Room::generateInviteCode();
        } while (Room::where('invite_code', $code)->exists());

        $this->room->update(['invite_code' => $code]);
        $this->logActivity('access_revoked', 'Host revoked access for all guests.');
        $this->redirect(route('dashboard'));
    }

    public function grantLocationException(int $memberId): void
    {
        if (! $this->isHost) {
            return;
        }

        $target = RoomMember::where('room_id', $this->room->id)->where('id', $memberId)->first();

        if (! $target) {
            return;
        }

        $target->update(['location_exempt' => true]);
        $this->logActivity('settings', "{$target->display_name} was exempted from the location check.");
        $this->broadcastUpdate('members');
    }

    public function kickMember(int $memberId): void
    {
        if (! $this->isHost) {
            return;
        }

        $target = RoomMember::where('room_id', $this->room->id)->where('id', $memberId)->first();

        if ($target && ! $target->isHost()) {
            $target->update(['left_at' => now()]);
            $this->logActivity('member_kicked', "{$target->display_name} was removed from the room.");
            $this->broadcastUpdate('members');
        }
    }

    public function approveMember(int $memberId): void
    {
        if (! $this->isHost) {
            return;
        }

        $target = RoomMember::where('room_id', $this->room->id)->where('id', $memberId)->first();

        if ($target && ! $target->isHost()) {
            $target->update(['approved_at' => now()]);
            $this->logActivity('member_approved', "{$target->display_name} was let into the room.");
            $this->broadcastUpdate('members');
        }
    }

    public function denyMember(int $memberId): void
    {
        if (! $this->isHost) {
            return;
        }

        $target = RoomMember::where('room_id', $this->room->id)->where('id', $memberId)->first();

        if ($target && ! $target->isHost()) {
            $target->update(['left_at' => now()]);
            $this->logActivity('member_denied', "{$target->display_name}'s request to join was denied.");
            $this->broadcastUpdate('members');
        }
    }

    public function verifyLocation(float $lat, float $lng): void
    {
        if ($this->room->isWithinBoundary($lat, $lng)) {
            $this->member->update(['location_verified_at' => now()]);
            $this->controlError = '';
        } else {
            $this->controlError = 'You need to be closer to the room to control playback.';
        }
    }

    public function leaveRoom()
    {
        if (! $this->isHost) {
            app(RoomMembership::class)->leave($this->member);
            $this->broadcastUpdate('members');
        }

        // Anonymous guests have no dashboard to go to — /dashboard would just
        // bounce them through the login screen, so send them to the homepage.
        return $this->redirect(auth()->check() ? route('dashboard') : url('/'));
    }

    public function closeRoom()
    {
        if (! $this->isHost) {
            return;
        }

        $this->logActivity('closed', 'Host closed the room.');
        $this->room->update(['closed_at' => now()]);

        return $this->redirect(route('dashboard'));
    }

    public function exportActivityLog()
    {
        $events = $this->room->activityEvents()->orderBy('created_at')->get();

        $csv = "timestamp,type,message\n";

        foreach ($events as $event) {
            $csv .= sprintf(
                "%s,%s,\"%s\"\n",
                $event->created_at->toIso8601String(),
                $event->type,
                str_replace('"', '""', $event->message)
            );
        }

        return response()->streamDownload(
            fn () => print($csv),
            "auxroom-{$this->room->invite_code}-activity.csv"
        );
    }

    /**
     * Prefers whatever device Spotify currently reports as active over a
     * manually-picked one — the manual "Choose device" picker in Host Hub
     * only mattered because nothing auto-detected this before. Falls back
     * to the last manual pick if nothing is currently active anywhere.
     */
    private function providerDeviceId(): ?string
    {
        $account = $this->room->playbackProvider?->spotifyAccount;

        if (! $account || ! $account->access_token) {
            return $account?->active_device_id;
        }

        $active = collect(app(SpotifyClientFactory::class)->forRoom($this->room)->getDevices())
            ->firstWhere('is_active', true);

        if ($active) {
            if ($account->active_device_id !== $active['id']) {
                $account->update(['active_device_id' => $active['id'], 'active_device_name' => $active['name']]);
            }

            return $active['id'];
        }

        return $account->active_device_id;
    }

    /**
     * Spotify's bare "resume" (an empty-body PUT to /player/play) relies on
     * its backend still remembering the paused context, which it silently
     * drops often enough that resuming this way was unreliable. Re-issuing
     * the exact track at its stored position works the same for the
     * listener but doesn't depend on Spotify remembering anything.
     *
     * Takes track details directly rather than a QueueItem, since "now
     * playing" isn't necessarily backed by one (a shuffled playlist track,
     * for instance) — the room's own now_playing_* fields are always the
     * source of truth for what's currently playing.
     */
    private function playTrackAt(
        string $spotifyTrackId,
        string $name,
        string $artist,
        ?string $albumArtUrl,
        int $durationMs,
        int $positionMs,
        ?int $queueItemId = null
    ): bool {
        $client = app(SpotifyClientFactory::class)->forRoom($this->room);

        if (! $client->playTrack('spotify:track:'.$spotifyTrackId, $this->providerDeviceId(), $positionMs)) {
            $this->controlError = "Spotify couldn't start playback. Try reselecting the device in Host Hub.";

            return false;
        }

        $this->room->update([
            'now_playing_queue_item_id' => $queueItemId,
            'now_playing_track_id' => $spotifyTrackId,
            'now_playing_name' => $name,
            'now_playing_artist' => $artist,
            'now_playing_album_art_url' => $albumArtUrl,
            'now_playing_duration_ms' => $durationMs,
            'now_playing_started_at' => now()->subMilliseconds($positionMs),
            'now_playing_position_ms' => $positionMs,
            'is_playing' => true,
            'is_playing_fallback' => false,
        ]);

        return true;
    }

    private function startPlayback(QueueItem $item): bool
    {
        if (! $this->playTrackAt($item->spotify_track_id, $item->name, $item->artist, $item->album_art_url, $item->duration_ms, 0, $item->id)) {
            return false;
        }

        $this->logActivity('played', "Now playing: \"{$item->name}\" by {$item->artist}.");

        return true;
    }

    /**
     * Hands playback off to Spotify's own shuffled playback of the fallback
     * playlist. AuxRoom doesn't manage its tracks one by one — Spotify keeps
     * it going on its own until a guest queues something, which interrupts it.
     */
    private function startFallbackPlayback(): bool
    {
        $client = app(SpotifyClientFactory::class)->forRoom($this->room);

        if (! $client->playContext($this->room->fallback_playlist_uri, $this->providerDeviceId(), true)) {
            $this->controlError = "Spotify couldn't start the fallback playlist. Try reselecting the device in Host Hub.";

            return false;
        }

        $this->room->update([
            'now_playing_queue_item_id' => null,
            'now_playing_track_id' => null,
            'now_playing_name' => null,
            'now_playing_artist' => null,
            'now_playing_album_art_url' => null,
            'now_playing_duration_ms' => null,
            'now_playing_started_at' => now(),
            'now_playing_position_ms' => 0,
            'is_playing' => true,
            'is_playing_fallback' => true,
        ]);

        $this->logActivity('played', "Fallback playlist started: \"{$this->room->fallback_playlist_name}\".");

        return true;
    }

    private function passesGate(string $ability): bool
    {
        $this->controlError = '';

        if (! $this->isApproved) {
            $this->controlError = 'Waiting for the host to let you in.';

            return false;
        }

        if (! $this->isHost && $ability === 'guests_can_add_to_queue' && ! $this->room->guests_can_add_to_queue) {
            $this->controlError = 'The host has locked the queue for everyone.';

            return false;
        }

        if (! $this->isHost && $ability !== 'guests_can_add_to_queue' && ! (bool) $this->member->{self::ABILITY_COLUMNS[$ability]}) {
            $this->controlError = 'The host hasn\'t given you this permission.';

            return false;
        }

        if (! $this->member->passesLocationCheck()) {
            $this->controlError = 'You need to verify you\'re near the room before controlling playback.';

            return false;
        }

        return true;
    }

    private function logActivity(string $type, string $message): void
    {
        ActivityEvent::create([
            'room_id' => $this->room->id,
            'member_id' => $this->member->id,
            'type' => $type,
            'message' => $message,
        ]);
    }

    private function broadcastUpdate(string $reason): void
    {
        broadcast(new RoomUpdated($this->room, $reason));
    }

    public function logout(Logout $logout): mixed
    {
        $logout();

        return $this->redirect('/', navigate: true);
    }

    public function render()
    {
        $this->dispatch('playback-sync',
            positionMs: $this->currentPositionMs,
            durationMs: $this->nowPlaying?->duration_ms ?? 0,
            isPlaying: $this->room->is_playing,
        );

        $view = view('livewire.dashboard.show');

        // Hosts get the same app shell (navbar, dropdown, hamburger) as every
        // other authenticated page. Guests aren't authenticated, so they get
        // a minimal standalone shell instead.
        if ($this->isHost) {
            return $view->layout('layouts.app');
        }

        return $view->layout('layouts.room', ['title' => $this->room->name]);
    }
}
