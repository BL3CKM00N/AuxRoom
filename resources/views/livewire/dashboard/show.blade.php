<div wire:poll.3s="heartbeat" x-data="roomLocation()" x-init="init()" class="min-h-screen flex flex-col bg-aux-bg text-aux-text">

@if (! $this->isApproved)

    {{-- Waiting for host approval --}}
    <div class="flex-1 flex flex-col items-center justify-center text-center px-6">
        <span class="inline-flex w-14 h-14 rounded-full bg-aux-accent-soft text-aux-accent items-center justify-center animate-pulse">
            <x-icon name="clock" class="w-6 h-6" />
        </span>
        <h1 class="mt-5 text-xl font-semibold">Waiting for the host to let you in</h1>
        <p class="mt-2 text-sm text-aux-muted max-w-sm">
            {{ $room->name }} is a private room. You'll join automatically as soon as the host approves your request. No need to refresh.
        </p>
        <button wire:click="leaveRoom" class="mt-6 text-sm text-aux-muted underline">Cancel and leave</button>
    </div>

@else

    {{-- Guests aren't authenticated, so they don't get the shared app navbar
         (it assumes auth()->user()) — this is their own minimal equivalent. --}}
    @unless ($this->isHost)
        <nav class="bg-aux-sidebar border-b border-aux-border">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16">
                    <div class="flex">
                        <div class="shrink-0 flex items-center gap-2 text-aux-text">
                            <span class="text-aux-accent"><x-icon name="zap" class="w-6 h-6" /></span>
                            <span class="font-semibold">AuxRoom</span>
                        </div>

                        <div class="hidden lg:flex items-center space-x-8 sm:ms-10">
                            <button wire:click="setTab('queue')"
                                    class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium {{ $activeTab === 'queue' ? 'border-aux-accent text-aux-text' : 'border-transparent text-aux-muted hover:text-aux-text hover:border-aux-border' }}">
                                Now Playing
                            </button>
                            <button wire:click="setTab('guests')"
                                    class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium {{ $activeTab === 'guests' ? 'border-aux-accent text-aux-text' : 'border-transparent text-aux-muted hover:text-aux-text hover:border-aux-border' }}">
                                Guests
                            </button>
                            <a href="{{ route('rooms.party', $room) }}" target="_blank"
                               class="inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium text-aux-muted hover:text-aux-text hover:border-aux-border">
                                Party Screen
                            </a>
                        </div>
                    </div>

                    <div class="hidden lg:flex items-center gap-4">
                        <span class="text-sm text-aux-muted">{{ $this->member->display_name }}</span>
                        <button wire:click="leaveRoom" class="text-sm text-aux-muted hover:text-aux-text">Leave</button>
                    </div>
                </div>
            </div>

            {{-- Mobile tab strip --}}
            <div class="flex lg:hidden items-center gap-1 px-3 py-2 border-t border-aux-border overflow-x-auto text-xs">
                <button wire:key="m-nav-queue" wire:click="setTab('queue')" class="shrink-0 px-3 py-1.5 rounded-full {{ $activeTab === 'queue' ? 'bg-aux-card-hover text-aux-accent' : 'text-aux-muted' }}">Now Playing</button>
                <button wire:key="m-nav-guests" wire:click="setTab('guests')" class="shrink-0 px-3 py-1.5 rounded-full {{ $activeTab === 'guests' ? 'bg-aux-card-hover text-aux-accent' : 'text-aux-muted' }}">Guests</button>
                <a wire:key="m-nav-party" href="{{ route('rooms.party', $room) }}" target="_blank" class="shrink-0 px-3 py-1.5 rounded-full text-aux-muted">Party</a>
                <button wire:key="m-nav-leave" wire:click="leaveRoom" class="shrink-0 px-3 py-1.5 rounded-full text-aux-muted">Leave</button>
            </div>
        </nav>
    @endunless

    <div class="flex-1 flex flex-col min-w-0">

        @if ($this->isMock)
            <div class="mx-4 sm:mx-6 mt-4 px-4 py-2.5 rounded-lg bg-aux-accent-soft border border-aux-accent/20 flex items-center justify-between gap-3 text-xs">
                <span class="text-aux-muted"><span class="text-aux-text font-medium">Demo room</span> &middot; sample tracks, real shared controls. No audio plays.</span>
                @if ($this->isHost)
                    <button wire:click="setTab('hub')" class="text-aux-accent font-medium shrink-0 flex items-center gap-1">Connect Spotify <x-icon name="chevron-right" class="w-3 h-3" /></button>
                @endif
            </div>
        @endif

        @if (session('status'))
            <div class="mx-4 sm:mx-6 mt-4 px-4 py-2.5 rounded-lg bg-aux-accent-soft border border-aux-accent/20 text-aux-accent text-xs">
                {{ session('status') }}
            </div>
        @endif

        @if ($controlError)
            <div class="mx-4 sm:mx-6 mt-4 px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-xs">
                {{ $controlError }}
            </div>
        @endif

        @if ($room->location_enforced && ! $this->member->passesLocationCheck())
            <div class="mx-4 sm:mx-6 mt-4 px-4 py-2.5 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-400 text-xs flex items-center justify-between gap-3">
                <span>This room requires you to be nearby to control playback.</span>
                <button type="button" @click="verify()" class="shrink-0 px-3 py-1.5 bg-amber-400 text-amber-950 rounded-full text-[11px] font-semibold">
                    Verify my location
                </button>
            </div>
        @endif

        {{-- Scrollable content --}}
        <div class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6 pb-32">

            @if ($activeTab === 'hub' && $this->isHost)
                @include('livewire.dashboard.tabs.hub')
            @elseif ($activeTab === 'guests')
                @include('livewire.dashboard.tabs.guests')
            @else
                @include('livewire.dashboard.tabs.queue')
            @endif

        </div>
    </div>

    {{-- Bottom player bar --}}
    <div class="fixed bottom-0 inset-x-0 bg-aux-sidebar border-t border-aux-border px-4 sm:px-6 py-3 flex items-center gap-4 z-20">
        <div class="flex items-center gap-3 shrink-0 min-w-0 sm:w-48">
            <div class="w-11 h-11 rounded-md bg-aux-card flex items-center justify-center shrink-0 overflow-hidden">
                @if ($this->nowPlaying?->album_art_url)
                    <img src="{{ $this->nowPlaying->album_art_url }}" class="w-full h-full object-cover" alt="">
                @else
                    <x-icon name="note" class="w-5 h-5 text-aux-faint" />
                @endif
            </div>
            <div class="min-w-0 hidden sm:block">
                <p class="text-sm font-medium truncate">{{ $this->nowPlaying->name ?? ($room->is_playing_fallback ? $room->fallback_playlist_name : 'Nothing playing') }}</p>
                <p class="text-xs text-aux-faint truncate">{{ $this->nowPlaying->artist ?? ($room->is_playing_fallback ? 'Playlist · shuffled' : ($this->isMock ? 'Connect Spotify to add a track' : 'Add a track to start')) }}</p>
            </div>
        </div>

        <div class="flex-1 flex flex-col items-center gap-1 min-w-0">
            <div class="flex items-center gap-4">
                <button wire:click="toggleShuffle" @disabled(! $this->canGuest('guests_can_play_pause'))
                        class="disabled:opacity-30 disabled:cursor-not-allowed {{ $room->shuffle_enabled ? 'text-aux-accent' : 'text-aux-muted hover:text-aux-text' }}">
                    <x-icon name="shuffle" class="w-4 h-4" />
                </button>
                <button wire:click="previous" @disabled((! $this->nowPlaying && ! $room->is_playing_fallback) || ! $this->canGuest('guests_can_skip'))
                        class="text-aux-muted hover:text-aux-text disabled:opacity-30 disabled:cursor-not-allowed">
                    <x-icon name="back" class="w-4 h-4" />
                </button>
                @if ($room->is_playing)
                    <button wire:click="pause" @disabled(! $this->canGuest('guests_can_play_pause'))
                            class="w-9 h-9 rounded-full bg-white text-black flex items-center justify-center disabled:opacity-30 disabled:cursor-not-allowed">
                        <x-icon name="pause" class="w-4 h-4" />
                    </button>
                @else
                    <button wire:click="play" @disabled((! $this->nowPlaying && $this->queue->isEmpty() && ! $room->fallback_playlist_uri) || ! $this->canGuest('guests_can_play_pause'))
                            class="w-9 h-9 rounded-full bg-white text-black flex items-center justify-center disabled:opacity-30 disabled:cursor-not-allowed">
                        <x-icon name="play" class="w-4 h-4" />
                    </button>
                @endif
                <button wire:click="skip" @disabled((! $this->nowPlaying && ! $room->is_playing_fallback) || ! $this->canGuest('guests_can_skip')) class="text-aux-muted hover:text-aux-text disabled:opacity-30 disabled:cursor-not-allowed">
                    <x-icon name="skip" class="w-4 h-4" />
                </button>
                <button wire:click="toggleRepeat" @disabled(! $this->canGuest('guests_can_play_pause'))
                        class="relative disabled:opacity-30 disabled:cursor-not-allowed {{ $room->repeat_mode !== 'off' ? 'text-aux-accent' : 'text-aux-muted hover:text-aux-text' }}">
                    <x-icon name="repeat" class="w-4 h-4" />
                    @if ($room->repeat_mode === 'track')
                        <span class="absolute -top-1.5 -right-1.5 w-3 h-3 rounded-full bg-aux-accent text-black text-[8px] font-bold flex items-center justify-center">1</span>
                    @endif
                </button>
            </div>
            <div class="w-full max-w-md min-w-0 flex items-center gap-2 text-[10px] text-aux-faint"
                 x-data="playbackClock()"
                 x-init="sync({ positionMs: {{ $this->currentPositionMs }}, durationMs: {{ $this->nowPlaying->duration_ms ?? 0 }}, isPlaying: {{ $room->is_playing ? 'true' : 'false' }} })"
                 x-on:playback-sync.window="sync($event.detail)">
                <span class="shrink-0" x-text="formatMs(positionMs)"></span>
                <input type="range" min="0" :max="durationMs || 100" :value="positionMs"
                       @disabled((! $this->nowPlaying && ! $room->is_playing_fallback) || ! $this->canGuest('guests_can_seek'))
                       @mousedown="dragging = true" @touchstart="dragging = true"
                       @mouseup="dragging = false" @touchend="dragging = false"
                       @input="positionMs = Number($event.target.value)"
                       :style="`background: linear-gradient(to right, #22c55e ${seekPct}%, rgba(255,255,255,0.12) ${seekPct}%); background-clip: content-box;`"
                       wire:change="seek($event.target.value)" class="seek-bar flex-1 min-w-0 disabled:opacity-30">
                <span class="shrink-0" x-text="formatMs(durationMs)"></span>
            </div>
        </div>

        <div class="hidden md:flex items-center gap-3 w-48 justify-end shrink-0">
            <button wire:click="setTab('queue')" class="flex items-center text-aux-muted hover:text-aux-text"><x-icon name="queue-list" class="w-4 h-4" /></button>
            @if ($this->isHost)
                <div class="relative flex items-center h-4" x-data="{ open: false }">
                    <button @click="open = !open" class="flex items-center text-aux-muted hover:text-aux-text">
                        <x-icon name="device" class="w-4 h-4" />
                    </button>
                    <div x-show="open" x-cloak @click.outside="open = false"
                         class="absolute right-0 bottom-full mb-2 w-56 rounded-lg bg-aux-card-hover border border-aux-border shadow-xl p-1 z-30">
                        @forelse ($this->devices as $device)
                            <button wire:click="selectDevice('{{ $device['id'] }}', '{{ $device['name'] }}')"
                                    class="w-full text-left text-xs px-3 py-2 rounded-md {{ ($room->playbackProvider?->spotifyAccount?->active_device_id ?? null) === $device['id'] ? 'bg-aux-accent-soft text-aux-accent' : 'hover:bg-white/5' }}">
                                {{ $device['name'] }}
                            </button>
                        @empty
                            <p class="text-xs text-aux-faint px-3 py-2">No devices found. Open Spotify somewhere first.</p>
                        @endforelse
                    </div>
                </div>
            @else
                <span class="flex items-center text-aux-faint opacity-40"><x-icon name="device" class="w-4 h-4" /></span>
            @endif
            <x-icon name="volume" class="w-4 h-4 {{ $this->canGuest('guests_can_set_volume') ? 'text-aux-muted' : 'text-aux-faint opacity-40' }}" />
            <div x-data="{ volume: {{ $room->volume_percent }} }" x-on:playback-sync.window="volume = $event.detail.volumePercent ?? volume">
                <input type="range" min="0" max="100" :value="volume" @disabled(! $this->canGuest('guests_can_set_volume'))
                       :style="`background: linear-gradient(to right, #22c55e ${volume}%, rgba(255,255,255,0.12) ${volume}%)`"
                       x-on:input="volume = $event.target.valueAsNumber"
                       wire:change="setVolume($event.target.value)" class="w-20 disabled:opacity-30">
            </div>
        </div>

        {{-- Mobile: same controls (queue shortcut, device, volume) tucked
             behind one touch-sized button instead of competing for space
             in the bar itself. --}}
        <div class="md:hidden relative shrink-0" x-data="{ open: false }">
            <button @click="open = !open" class="w-11 h-11 -m-1 flex items-center justify-center text-aux-muted hover:text-aux-text">
                <x-icon name="volume" class="w-5 h-5" />
            </button>
            <div x-show="open" x-cloak @click.outside="open = false"
                 class="absolute right-0 bottom-full mb-3 w-64 rounded-xl bg-aux-card-hover border border-aux-border shadow-xl p-2 z-30">
                <button wire:click="setTab('queue')" @click="open = false"
                        class="w-full flex items-center gap-3 px-3 py-3 rounded-lg hover:bg-white/5 text-sm">
                    <x-icon name="queue-list" class="w-5 h-5" /> Queue
                </button>

                @if ($this->isHost)
                    <p class="mt-1 px-3 pt-2 text-[11px] uppercase tracking-wide text-aux-faint">Device</p>
                    @forelse ($this->devices as $device)
                        <button wire:click="selectDevice('{{ $device['id'] }}', '{{ $device['name'] }}')"
                                class="w-full text-left px-3 py-3 rounded-lg text-sm {{ ($room->playbackProvider?->spotifyAccount?->active_device_id ?? null) === $device['id'] ? 'bg-aux-accent-soft text-aux-accent' : 'hover:bg-white/5' }}">
                            {{ $device['name'] }}
                        </button>
                    @empty
                        <p class="px-3 py-2 text-xs text-aux-faint">No devices found. Open Spotify somewhere first.</p>
                    @endforelse
                @endif

                <div class="px-3 pt-3 pb-1" x-data="{ volume: {{ $room->volume_percent }} }" x-on:playback-sync.window="volume = $event.detail.volumePercent ?? volume">
                    <div class="flex items-center justify-between text-[11px] text-aux-faint mb-2">
                        <span class="uppercase tracking-wide">Volume</span>
                        <span x-text="volume"></span>
                    </div>
                    <input type="range" min="0" max="100" :value="volume" @disabled(! $this->canGuest('guests_can_set_volume'))
                           :style="`background: linear-gradient(to right, #22c55e ${volume}%, rgba(255,255,255,0.12) ${volume}%); background-clip: content-box;`"
                           x-on:input="volume = $event.target.valueAsNumber"
                           wire:change="setVolume($event.target.value)" class="seek-bar w-full disabled:opacity-30">
                </div>
            </div>
        </div>
    </div>

@endif

    {{-- Confirm modal — replaces native confirm() dialogs --}}
    @if ($confirmAction)
        <div class="fixed inset-0 z-40 flex items-center justify-center px-4">
            <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" wire:click="confirmNo"></div>
            <div class="relative w-full max-w-sm bg-aux-card border border-aux-border rounded-xl p-6 text-center">
                <span class="inline-flex w-11 h-11 rounded-full items-center justify-center {{ $confirmDanger ? 'bg-red-500/10 text-red-400' : 'bg-aux-accent-soft text-aux-accent' }}">
                    <x-icon name="{{ $confirmDanger ? 'exit' : 'zap' }}" class="w-5 h-5" />
                </span>
                <p class="mt-4 text-sm text-aux-text">{{ $confirmMessage }}</p>
                <div class="mt-5 flex items-center gap-3">
                    <button wire:click="confirmNo" class="flex-1 py-2 rounded-full border border-aux-border text-sm font-medium hover:bg-aux-card-hover">
                        Cancel
                    </button>
                    <button wire:click="confirmYes" class="flex-1 py-2 rounded-full text-sm font-semibold {{ $confirmDanger ? 'bg-red-500 text-white hover:bg-red-400' : 'bg-aux-accent text-black hover:bg-aux-accent-strong' }}">
                        {{ $confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Playlist picker — search or browse, then pick one to play immediately --}}
    @if ($showPlaylistPicker)
        <div class="fixed inset-0 z-40 flex items-center justify-center px-4">
            <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" wire:click="closePlaylistPicker"></div>
            <div class="relative w-full max-w-lg max-h-[80vh] flex flex-col bg-aux-card border border-aux-border rounded-xl p-6">
                <div class="flex items-center justify-between shrink-0">
                    <h3 class="font-semibold">Choose a playlist</h3>
                    <button wire:click="closePlaylistPicker" class="text-aux-muted hover:text-aux-text">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="mt-4 relative shrink-0">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-aux-faint">
                        <x-icon name="search" class="w-4 h-4" />
                    </span>
                    <input type="text" wire:model.live.debounce.400ms="playlistQuery" autofocus
                           placeholder="Paste a playlist link or search…"
                           class="w-full pl-9 pr-3 py-2 rounded-full bg-aux-card-hover border border-aux-border text-sm placeholder:text-aux-faint focus:outline-none focus:ring-1 focus:ring-aux-accent">
                </div>

                @if (trim($playlistQuery) !== '')
                    <button type="button" wire:click="browseMyPlaylists" class="mt-2 self-start text-xs font-medium text-aux-accent shrink-0">
                        Clear and show my playlists
                    </button>
                @endif

                <ul class="mt-4 space-y-1 overflow-y-auto">
                    @forelse ($playlistResults as $i => $p)
                        <li class="flex items-center gap-3 p-2 rounded-lg hover:bg-white/5">
                            <div class="w-11 h-11 rounded-md bg-aux-card-hover flex items-center justify-center shrink-0 overflow-hidden">
                                @if ($p['image_url'])
                                    <img src="{{ $p['image_url'] }}" class="w-full h-full object-cover" alt="">
                                @else
                                    <x-icon name="queue-list" class="w-4 h-4 text-aux-faint" />
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ $p['name'] }}</p>
                                <p class="truncate text-xs text-aux-faint">{{ $p['owner'] }}{{ $p['track_count'] !== null ? ' · '.$p['track_count'].' tracks' : '' }}</p>
                            </div>
                            <button wire:click="playPlaylist({{ $i }})"
                                    class="shrink-0 px-3 py-1.5 rounded-full bg-aux-accent text-black text-xs font-semibold hover:bg-aux-accent-strong">
                                Play
                            </button>
                        </li>
                    @empty
                        <li class="py-6 text-center text-sm text-aux-faint">
                            {{ trim($playlistQuery) !== '' ? 'No playlists found.' : "You don't have any playlists yet." }}
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>
    @endif
</div>

<script>
    function roomLocation() {
        return {
            init() {},
            verify() {
                navigator.geolocation.getCurrentPosition((pos) => {
                    @this.call('verifyLocation', pos.coords.latitude, pos.coords.longitude);
                }, () => {
                    alert('Could not get your location.');
                });
            },
            setBoundary() {
                navigator.geolocation.getCurrentPosition((pos) => {
                    const radius = prompt('Radius in meters?', '{{ $room->location_radius_m ?? 250 }}');
                    if (radius) {
                        @this.call('setLocationBoundary', pos.coords.latitude, pos.coords.longitude, parseInt(radius, 10));
                    }
                }, () => {
                    alert('Could not get your location.');
                });
            },
            copyLink() {
                navigator.clipboard.writeText('{{ route('join', ['code' => $room->invite_code]) }}');
            },
        };
    }
</script>
