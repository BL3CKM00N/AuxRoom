<div wire:poll.5s="heartbeat" x-data="roomLocation()" x-init="init()" class="min-h-screen flex flex-col bg-aux-bg text-aux-text">

@if (! $this->isApproved)

    {{-- Waiting for host approval --}}
    <div class="flex-1 flex flex-col items-center justify-center text-center px-6">
        <span class="inline-flex w-14 h-14 rounded-full bg-aux-accent-soft text-aux-accent items-center justify-center animate-pulse">
            <x-icon name="clock" class="w-6 h-6" />
        </span>
        <h1 class="mt-5 text-xl font-semibold">Waiting for the host to let you in</h1>
        <p class="mt-2 text-sm text-aux-muted max-w-sm">
            {{ $room->name }} is a private room. You'll join automatically as soon as the host approves your request — no need to refresh.
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
                                Collaborative Queue
                            </button>
                            <button wire:click="setTab('guests')"
                                    class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium {{ $activeTab === 'guests' ? 'border-aux-accent text-aux-text' : 'border-transparent text-aux-muted hover:text-aux-text hover:border-aux-border' }}">
                                Guest Portal
                            </button>
                            <a href="{{ route('rooms.party', $room) }}" target="_blank"
                               class="inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium text-aux-muted hover:text-aux-text hover:border-aux-border">
                                Party Display
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
                <button wire:key="m-nav-queue" wire:click="setTab('queue')" class="shrink-0 px-3 py-1.5 rounded-full {{ $activeTab === 'queue' ? 'bg-aux-card-hover text-aux-accent' : 'text-aux-muted' }}">Queue</button>
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
        <div class="flex items-center gap-3 w-48 shrink-0 min-w-0">
            <div class="w-11 h-11 rounded-md bg-aux-card flex items-center justify-center shrink-0 overflow-hidden">
                @if ($this->nowPlaying?->album_art_url)
                    <img src="{{ $this->nowPlaying->album_art_url }}" class="w-full h-full object-cover" alt="">
                @else
                    <x-icon name="note" class="w-5 h-5 text-aux-faint" />
                @endif
            </div>
            <div class="min-w-0 hidden sm:block">
                <p class="text-sm font-medium truncate">{{ $this->nowPlaying->name ?? ($room->is_playing_fallback ? $room->fallback_playlist_name : 'Nothing playing') }}</p>
                <p class="text-xs text-aux-faint truncate">{{ $this->nowPlaying->artist ?? ($room->is_playing_fallback ? 'Fallback playlist · shuffled' : ($this->isMock ? 'Connect Spotify to add a track' : 'Add a track to start')) }}</p>
            </div>
        </div>

        <div class="flex-1 flex flex-col items-center gap-1 min-w-0">
            <div class="flex items-center gap-4">
                <button disabled class="text-aux-faint opacity-40 cursor-default"><x-icon name="shuffle" class="w-4 h-4" /></button>
                <button disabled class="text-aux-faint opacity-40 cursor-default"><x-icon name="back" class="w-4 h-4" /></button>
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
                <button wire:click="skip" @disabled(! $this->nowPlaying || ! $this->canGuest('guests_can_skip')) class="text-aux-muted hover:text-aux-text disabled:opacity-30 disabled:cursor-not-allowed">
                    <x-icon name="skip" class="w-4 h-4" />
                </button>
                <button disabled class="text-aux-faint opacity-40 cursor-default"><x-icon name="repeat" class="w-4 h-4" /></button>
            </div>
            <div class="w-full max-w-md flex items-center gap-2 text-[10px] text-aux-faint">
                <span>{{ gmdate('i:s', intdiv($this->currentPositionMs, 1000)) }}</span>
                @php $seekPct = $this->nowPlaying && $this->nowPlaying->duration_ms > 0 ? min(100, ($this->currentPositionMs / $this->nowPlaying->duration_ms) * 100) : 0; @endphp
                <input type="range" min="0" max="{{ $this->nowPlaying->duration_ms ?? 100 }}"
                       value="{{ $this->currentPositionMs }}" @disabled(! $this->nowPlaying || ! $this->canGuest('guests_can_seek'))
                       style="background: linear-gradient(to right, #22c55e {{ $seekPct }}%, rgba(255,255,255,0.12) {{ $seekPct }}%)"
                       oninput="this.style.background = `linear-gradient(to right, #22c55e ${(this.value/this.max)*100}%, rgba(255,255,255,0.12) ${(this.value/this.max)*100}%)`"
                       wire:change="seek($event.target.value)" class="flex-1 disabled:opacity-30">
                <span>{{ gmdate('i:s', intdiv($this->nowPlaying->duration_ms ?? 0, 1000)) }}</span>
            </div>
        </div>

        <div class="hidden md:flex items-center gap-3 w-48 justify-end shrink-0">
            <button wire:click="setTab('queue')" class="text-aux-muted hover:text-aux-text"><x-icon name="queue-list" class="w-4 h-4" /></button>
            <span class="text-aux-muted"><x-icon name="device" class="w-4 h-4" /></span>
            <x-icon name="volume" class="w-4 h-4 {{ $this->canGuest('guests_can_set_volume') ? 'text-aux-muted' : 'text-aux-faint opacity-40' }}" />
            <input type="range" min="0" max="100" value="{{ $room->volume_percent }}" @disabled(! $this->canGuest('guests_can_set_volume'))
                   style="background: linear-gradient(to right, #22c55e {{ $room->volume_percent }}%, rgba(255,255,255,0.12) {{ $room->volume_percent }}%)"
                   oninput="this.style.background = `linear-gradient(to right, #22c55e ${this.value}%, rgba(255,255,255,0.12) ${this.value}%)`"
                   wire:change="setVolume($event.target.value)" class="w-20 disabled:opacity-30">
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
            copyCode() {
                navigator.clipboard.writeText('{{ $room->invite_code }}');
            },
        };
    }
</script>
