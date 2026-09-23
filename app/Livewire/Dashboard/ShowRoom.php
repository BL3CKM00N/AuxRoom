<?php

namespace App\Livewire\Dashboard;

use App\Events\RoomUpdated;
use App\Livewire\Actions\Logout;
use App\Livewire\Dashboard\Concerns\HandlesLocationEnforcement;
use App\Livewire\Dashboard\Concerns\HandlesPlayback;
use App\Livewire\Dashboard\Concerns\HandlesPlaylistSearch;
use App\Livewire\Dashboard\Concerns\ManagesConfirmModal;
use App\Livewire\Dashboard\Concerns\ManagesGuestPermissions;
use App\Models\ActivityEvent;
use App\Models\Room;
use App\Models\RoomMember;
use App\Services\RoomMembership;
use App\Services\Spotify\SpotifyClientFactory;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class ShowRoom extends Component
{
    use HandlesLocationEnforcement;
    use HandlesPlayback;
    use HandlesPlaylistSearch;
    use ManagesConfirmModal;
    use ManagesGuestPermissions;

    public Room $room;

    public int $memberId;

    public string $controlError = '';

    #[Url(as: 'tab')]
    public string $activeTab = 'queue';

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

        if (! request()->has('tab')) {
            $this->activeTab = 'queue';
        }
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
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
        //
        // Skipped entirely for a few seconds after any local command: the
        // Spotify API has a well-known read-after-write lag, so a poll
        // landing right after play/pause/skip can still read the old state
        // and immediately overwrite the correct local one with it — the
        // command visibly "undoing itself" a moment after being pressed.
        if ($this->isHost && ! $this->room->commandedRecently()) {
            $this->syncWithSpotify();
        }

        return null;
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

    public function getOnlineCountProperty(): int
    {
        return $this->members->filter(fn (RoomMember $m) => $m->isOnline())->count();
    }

    /**
     * Everything Spotify reports as coming up — both guest-queued tracks and
     * upcoming tracks from a playing playlist's shuffle, tagged so the two
     * can be told apart (is_queued). Playlist tracks were never "added" by
     * anyone, so they shouldn't count toward queue counts or attribution.
     *
     * "Up Next" is Spotify's own live queue, not a local reconstruction —
     * rebuilding it from locally-tracked positions is what caused it to
     * play backwards once Previous/Next could move either direction.
     * Attribution ("added by") is looked up per track, but never used to
     * decide order or content.
     */
    public function getQueueProperty()
    {
        if ($this->isMock || ! $this->room->playbackProvider?->hasSpotifyConnected()) {
            return collect();
        }

        $upcoming = app(SpotifyClientFactory::class)->forRoom($this->room)->getQueue();

        // With repeat-track on, Spotify's own queue is just the currently
        // playing track cycling forever — its raw "queue" array reflects
        // that literally, listing the same track dozens of times. Collapse
        // to unique tracks so "Up Next" doesn't show one song repeated.
        return collect($upcoming)->unique('id')->map(function (array $track) {
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
        RoomUpdated::broadcastFor($this->room, $reason);
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
            volumePercent: $this->room->volume_percent,
        );

        $this->dispatch('location-status',
            enforced: $this->room->location_enforced,
            verified: $this->isHost || $this->member->passesLocationCheck(),
            // Lets the guest's browser notice the host moved the boundary and
            // react immediately instead of waiting for the next scheduled
            // recheck — see roomLocation() in show.blade.php.
            boundary: $this->room->hasLocationBoundary()
                ? "{$this->room->location_lat},{$this->room->location_lng},{$this->room->location_radius_m}"
                : null,
        );

        $view = view('livewire.dashboard.show');

        // Hosts get the same app shell (navbar, dropdown, hamburger) as every
        // other authenticated page. Guests aren't authenticated, so they get
        // a minimal standalone shell instead.
        if ($this->isHost) {
            return $view->layout('layouts.app');
        }

        return $view->layout('layouts.room', ['title' => 'Room', 'padTop' => true]);
    }
}
