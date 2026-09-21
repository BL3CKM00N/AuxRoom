{{-- Collaborative Queue --}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6">

        {{-- Now playing --}}
        <div class="p-6 rounded-xl bg-gradient-to-br from-aux-card to-aux-bg border border-aux-border">
            <div class="flex gap-5">
                <div class="w-28 h-28 rounded-lg bg-aux-card-hover flex items-center justify-center shrink-0 overflow-hidden">
                    @if ($this->nowPlaying?->album_art_url)
                        <img src="{{ $this->nowPlaying->album_art_url }}" class="w-full h-full object-cover" alt="">
                    @else
                        <x-icon name="note" class="w-8 h-8 text-aux-faint" />
                    @endif
                </div>
                <div class="min-w-0 flex-1">
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-aux-accent">
                        <x-icon name="note" class="w-3.5 h-3.5" /> {{ $room->is_playing ? 'Now spinning' : 'On pause' }}
                    </span>
                    <h2 class="text-2xl font-bold mt-1 truncate">{{ $this->nowPlaying->name ?? ($room->is_playing_fallback ? $room->fallback_playlist_name : 'Nothing queued yet') }}</h2>
                    <p class="text-aux-muted truncate">{{ $this->nowPlaying->artist ?? ($room->is_playing_fallback ? 'Fallback playlist · shuffled' : ($this->isMock ? 'Connect Spotify to add a track' : 'Search below to add the first track')) }}</p>

                    @if ($this->nowPlaying)
                        <div class="mt-3 flex items-center gap-2 text-[11px] text-aux-faint"
                             x-data="playbackClock()"
                             x-init="sync({ positionMs: {{ $this->currentPositionMs }}, durationMs: {{ $this->nowPlaying->duration_ms }}, isPlaying: {{ $room->is_playing ? 'true' : 'false' }} })"
                             x-on:playback-sync.window="sync($event.detail)">
                            <span x-text="formatMs(positionMs)"></span>
                            <input type="range" min="0" :max="durationMs || 100" :value="positionMs"
                                   wire:change="seek($event.target.value)"
                                   @mousedown="dragging = true" @touchstart="dragging = true"
                                   @mouseup="dragging = false" @touchend="dragging = false"
                                   @input="positionMs = Number($event.target.value)"
                                   :style="`background: linear-gradient(to right, #22c55e ${seekPct}%, rgba(255,255,255,0.12) ${seekPct}%)`"
                                   @disabled(! $this->canGuest('guests_can_seek')) class="flex-1 disabled:opacity-30">
                            <span x-text="formatMs(durationMs)"></span>
                        </div>

                        <div class="mt-3 flex items-center gap-3">
                            @if ($this->isMock)
                                <span class="px-2.5 py-1 rounded-full bg-white/5 text-[10px] font-semibold text-aux-muted">SAMPLE TRACK</span>
                            @endif
                            @if ($room->is_playing)
                                <button wire:click="pause" @disabled(! $this->canGuest('guests_can_play_pause'))
                                        class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full bg-white/10 text-sm font-medium disabled:opacity-30 disabled:cursor-not-allowed">
                                    <x-icon name="pause" class="w-3.5 h-3.5" /> Pause
                                </button>
                            @else
                                <button wire:click="play" @disabled(! $this->canGuest('guests_can_play_pause'))
                                        class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full bg-aux-accent text-black text-sm font-semibold disabled:opacity-30 disabled:cursor-not-allowed">
                                    <x-icon name="play" class="w-3.5 h-3.5" /> Play
                                </button>
                            @endif
                            <button wire:click="skip" @disabled(! $this->canGuest('guests_can_skip'))
                                    class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full bg-aux-accent text-black text-sm font-semibold disabled:opacity-30 disabled:cursor-not-allowed">
                                <x-icon name="skip" class="w-3.5 h-3.5" /> Skip track
                            </button>
                        </div>
                    @else
                        <button wire:click="play" @disabled(($this->queue->isEmpty() && ! $room->fallback_playlist_uri) || ! $this->canGuest('guests_can_play_pause'))
                                class="mt-3 inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full bg-aux-accent text-black text-sm font-semibold disabled:opacity-30 disabled:cursor-not-allowed">
                            <x-icon name="play" class="w-3.5 h-3.5" /> Play
                        </button>
                    @endif
                </div>
            </div>
        </div>

        {{-- Up next --}}
        <div class="p-6 rounded-xl bg-aux-card border border-aux-border">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h3 class="font-semibold">Up Next</h3>
                    <span class="px-2 py-0.5 rounded-full bg-aux-accent-soft text-aux-accent text-[11px] font-semibold">{{ $this->queue->count() }} tracks</span>
                </div>
                <button type="button" @click="$refs.queueSearch.focus()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-aux-card-hover text-xs font-medium">
                    <x-icon name="plus" class="w-3.5 h-3.5" /> Add a song
                </button>
            </div>

            <ul class="mt-4 divide-y divide-white/5">
                @forelse ($this->queue as $i => $item)
                    <li class="py-3 flex items-center gap-3">
                        <span class="w-5 text-xs text-aux-accent font-semibold shrink-0">#{{ $i + 1 }}</span>
                        <div class="w-10 h-10 rounded-md bg-aux-card-hover flex items-center justify-center shrink-0 overflow-hidden">
                            @if ($item->album_art_url)
                                <img src="{{ $item->album_art_url }}" class="w-full h-full object-cover" alt="">
                            @else
                                <x-icon name="note" class="w-4 h-4 text-aux-faint" />
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ $item->name }}</p>
                            <p class="truncate text-xs text-aux-faint">{{ $item->artist }} &middot; added by {{ $item->addedBy?->display_name ?? 'Unknown' }}</p>
                        </div>
                        <span class="shrink-0 text-xs text-aux-faint">{{ gmdate('i:s', intdiv($item->duration_ms, 1000)) }}</span>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-aux-faint">Queue is empty — add the first track.</li>
                @endforelse
            </ul>
        </div>
    </div>

    <div class="space-y-6">

        {{-- Find music --}}
        <div class="p-5 rounded-xl bg-aux-card border border-aux-border">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold">Find Music</h3>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $room->guests_can_add_to_queue ? 'bg-aux-accent-soft text-aux-accent' : 'bg-red-500/10 text-red-400' }}">
                    {{ $room->guests_can_add_to_queue ? 'AUX OPEN' : 'QUEUE LOCKED' }}
                </span>
            </div>

            @if ($this->isMock)
                <p class="mt-3 text-sm text-aux-faint">
                    {{ $this->isHost ? 'Connect Spotify in Host Hub to search for real songs.' : "The host hasn't connected Spotify yet — song search isn't available." }}
                </p>
            @else
                <div class="mt-3 relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-aux-faint">
                        <x-icon name="search" class="w-4 h-4" />
                    </span>
                    <input type="text" wire:model.live.debounce.400ms="search" x-ref="queueSearch"
                           @disabled(! $this->canGuest('guests_can_add_to_queue'))
                           placeholder="{{ $this->canGuest('guests_can_add_to_queue') ? 'Search songs or playlists…' : 'The host has locked the queue' }}"
                           class="w-full pl-9 pr-3 py-2 rounded-full bg-aux-card-hover border border-aux-border text-sm placeholder:text-aux-faint focus:outline-none focus:ring-1 focus:ring-aux-accent disabled:opacity-40 disabled:cursor-not-allowed">
                </div>

                @if (count($searchResults))
                    <p class="mt-4 text-[10px] uppercase tracking-widest text-aux-faint">Search results</p>
                @endif

                <ul class="mt-2 space-y-1">
                    @forelse ($searchResults as $i => $track)
                        <li class="flex items-center gap-3 p-2 rounded-lg hover:bg-white/5">
                            <div class="w-9 h-9 rounded-md bg-aux-card-hover flex items-center justify-center shrink-0 overflow-hidden">
                                @if ($track['album_art_url'])
                                    <img src="{{ $track['album_art_url'] }}" class="w-full h-full object-cover" alt="">
                                @else
                                    <x-icon name="note" class="w-4 h-4 text-aux-faint" />
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ $track['name'] }}</p>
                                <p class="truncate text-xs text-aux-faint">{{ $track['artist'] }}</p>
                            </div>
                            <button wire:click="addToQueue({{ $i }})" @disabled(! $this->canGuest('guests_can_add_to_queue'))
                                    class="shrink-0 w-6 h-6 rounded-full bg-aux-accent text-black flex items-center justify-center disabled:opacity-30 disabled:cursor-not-allowed">
                                <x-icon name="plus" class="w-3.5 h-3.5" />
                            </button>
                        </li>
                    @empty
                        <li class="text-sm text-aux-faint py-3">
                            {{ $this->canGuest('guests_can_add_to_queue') ? 'Search for a song to add it to the queue.' : 'The host has locked the queue — no new songs can be added right now.' }}
                        </li>
                    @endforelse
                </ul>
            @endif

            <div class="mt-4 pt-4 border-t border-aux-border grid grid-cols-3 text-center">
                <div>
                    <p class="text-lg font-bold text-aux-accent">{{ $this->onlineCount }}</p>
                    <p class="text-[10px] uppercase tracking-wide text-aux-faint">Online</p>
                </div>
                <div>
                    <p class="text-lg font-bold">{{ $this->queue->count() }}</p>
                    <p class="text-[10px] uppercase tracking-wide text-aux-faint">Queued</p>
                </div>
                <div>
                    <p class="text-lg font-bold">{{ $room->location_radius_m ?? '—' }}{{ $room->location_radius_m ? ' m' : '' }}</p>
                    <p class="text-[10px] uppercase tracking-wide text-aux-faint">Control radius</p>
                </div>
            </div>
        </div>

        {{-- Fallback playlist --}}
        @if ($this->isMock)
            <div class="p-5 rounded-xl bg-aux-card border border-aux-border">
                <h3 class="font-semibold">Fallback Playlist</h3>
                <p class="mt-1 text-xs text-aux-faint">Plays automatically, shuffled, whenever the queue runs dry.</p>
                <p class="mt-3 text-sm text-aux-faint">
                    {{ $this->isHost ? 'Connect Spotify in Host Hub to set a fallback playlist.' : "The host hasn't connected Spotify yet — a fallback playlist isn't available." }}
                </p>
            </div>
        @elseif ($this->canGuest('guests_can_manage_playlist'))
            <div class="p-5 rounded-xl bg-aux-card border border-aux-border">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold">Fallback Playlist</h3>
                    @if ($room->is_playing_fallback)
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-aux-accent-soft text-aux-accent">PLAYING</span>
                    @endif
                </div>
                <p class="mt-1 text-xs text-aux-faint">Plays automatically, shuffled, whenever the queue runs dry.</p>

                @if ($room->fallback_playlist_uri)
                    <div class="mt-3 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-md bg-aux-card-hover flex items-center justify-center overflow-hidden shrink-0">
                            @if ($room->fallback_playlist_image_url)
                                <img src="{{ $room->fallback_playlist_image_url }}" class="w-full h-full object-cover" alt="">
                            @else
                                <x-icon name="queue-list" class="w-4 h-4 text-aux-faint" />
                            @endif
                        </div>
                        <p class="min-w-0 flex-1 truncate text-sm font-medium">{{ $room->fallback_playlist_name }}</p>
                        <button wire:click="clearFallbackPlaylist" class="shrink-0 text-[11px] font-medium text-red-400 hover:text-red-300">
                            Remove
                        </button>
                    </div>
                @endif

                <div class="mt-3 relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-aux-faint">
                        <x-icon name="search" class="w-4 h-4" />
                    </span>
                    <input type="text" wire:model.live.debounce.400ms="playlistQuery"
                           placeholder="Paste a playlist link or search…"
                           class="w-full pl-9 pr-3 py-2 rounded-full bg-aux-card-hover border border-aux-border text-sm placeholder:text-aux-faint focus:outline-none focus:ring-1 focus:ring-aux-accent">
                </div>

                <button type="button" wire:click="browseMyPlaylists" class="mt-2 text-xs font-medium text-aux-accent inline-flex items-center gap-1">
                    Browse my playlists <x-icon name="chevron-right" class="w-3 h-3" />
                </button>

                @if (! empty($playlistResults))
                    <ul class="mt-3 space-y-1 max-h-56 overflow-y-auto">
                        @foreach ($playlistResults as $i => $p)
                            <li class="flex items-center gap-3 p-2 rounded-lg hover:bg-white/5">
                                <div class="w-9 h-9 rounded-md bg-aux-card-hover flex items-center justify-center shrink-0 overflow-hidden">
                                    @if ($p['image_url'])
                                        <img src="{{ $p['image_url'] }}" class="w-full h-full object-cover" alt="">
                                    @else
                                        <x-icon name="queue-list" class="w-4 h-4 text-aux-faint" />
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium">{{ $p['name'] }}</p>
                                    <p class="truncate text-xs text-aux-faint">{{ $p['owner'] }} &middot; {{ $p['track_count'] }} tracks</p>
                                </div>
                                <button wire:click="selectFallbackPlaylist({{ $i }})"
                                        class="shrink-0 px-2.5 py-1 rounded-full bg-aux-accent text-black text-xs font-semibold">
                                    Use
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @elseif ($room->fallback_playlist_uri)
            <div class="p-5 rounded-xl bg-aux-card border border-aux-border">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold">Fallback Playlist</h3>
                    @if ($room->is_playing_fallback)
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-aux-accent-soft text-aux-accent">PLAYING</span>
                    @endif
                </div>
                <p class="mt-1 text-xs text-aux-faint">Plays automatically when the queue runs dry.</p>
                <div class="mt-3 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-md bg-aux-card-hover flex items-center justify-center overflow-hidden shrink-0">
                        @if ($room->fallback_playlist_image_url)
                            <img src="{{ $room->fallback_playlist_image_url }}" class="w-full h-full object-cover" alt="">
                        @else
                            <x-icon name="queue-list" class="w-4 h-4 text-aux-faint" />
                        @endif
                    </div>
                    <p class="min-w-0 flex-1 truncate text-sm font-medium">{{ $room->fallback_playlist_name }}</p>
                </div>
            </div>
        @endif

        @include('livewire.dashboard.tabs.partials.activity')
    </div>
</div>
