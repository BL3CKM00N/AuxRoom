{{-- Now Playing --}}
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
                    <p class="text-aux-muted truncate">{{ $this->nowPlaying->artist ?? ($room->is_playing_fallback ? 'Playlist · shuffled' : ($this->isMock ? 'Connect Spotify to add a track' : 'Search below to add the first track')) }}</p>

                    @if ($this->nowPlaying || $room->is_playing_fallback)
                        <div class="mt-3 flex items-center gap-2 text-[11px] text-aux-faint"
                             x-data="playbackClock()"
                             x-init="sync({ positionMs: {{ $this->currentPositionMs }}, durationMs: {{ $this->nowPlaying->duration_ms ?? 0 }}, isPlaying: {{ $room->is_playing ? 'true' : 'false' }} })"
                             x-on:playback-sync.window="sync($event.detail)">
                            <span x-text="formatMs(positionMs)"></span>
                            <input type="range" min="0" :max="durationMs || 100" :value="positionMs"
                                   wire:change="seek($event.target.value)"
                                   @mousedown="dragging = true" @touchstart="dragging = true"
                                   @mouseup="dragging = false" @touchend="dragging = false"
                                   @input="positionMs = Number($event.target.value)"
                                   :style="`background: linear-gradient(to right, #22c55e ${seekPct}%, rgba(255,255,255,0.12) ${seekPct}%); background-clip: content-box;`"
                                   @disabled(! $this->canGuest('guests_can_seek')) class="seek-bar flex-1 disabled:opacity-30">
                            <span x-text="formatMs(durationMs)"></span>
                        </div>

                        <div class="mt-3 flex items-center gap-3">
                            @if ($this->isMock)
                                <span class="px-2.5 py-1 rounded-full bg-white/5 text-[10px] font-semibold text-aux-muted">SAMPLE TRACK</span>
                            @endif
                            <button wire:click="toggleShuffle" @disabled(! $this->canGuest('guests_can_play_pause'))
                                    class="disabled:opacity-30 disabled:cursor-not-allowed {{ $room->shuffle_enabled ? 'text-aux-accent' : 'text-aux-muted hover:text-aux-text' }}">
                                <x-icon name="shuffle" class="w-4 h-4" />
                            </button>
                            <button wire:click="previous" @disabled(! $this->canGuest('guests_can_skip'))
                                    class="inline-flex items-center gap-1 text-aux-muted hover:text-aux-text disabled:opacity-30 disabled:cursor-not-allowed">
                                <x-icon name="back" class="w-4 h-4" /> Previous
                            </button>
                            @if ($room->is_playing)
                                <button wire:click="pause" @disabled(! $this->canGuest('guests_can_play_pause'))
                                        class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full bg-aux-accent text-black text-sm font-semibold disabled:opacity-30 disabled:cursor-not-allowed">
                                    <x-icon name="pause" class="w-3.5 h-3.5" /> Pause
                                </button>
                            @else
                                <button wire:click="play" @disabled(! $this->canGuest('guests_can_play_pause'))
                                        class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full bg-aux-accent text-black text-sm font-semibold disabled:opacity-30 disabled:cursor-not-allowed">
                                    <x-icon name="play" class="w-3.5 h-3.5" /> Play
                                </button>
                            @endif
                            <button wire:click="skip" @disabled(! $this->canGuest('guests_can_skip'))
                                    class="inline-flex items-center gap-1 text-aux-muted hover:text-aux-text disabled:opacity-30 disabled:cursor-not-allowed">
                                <x-icon name="skip" class="w-4 h-4" /> Skip track
                            </button>
                            <button wire:click="toggleRepeat" @disabled(! $this->canGuest('guests_can_play_pause'))
                                    class="relative disabled:opacity-30 disabled:cursor-not-allowed {{ $room->repeat_mode !== 'off' ? 'text-aux-accent' : 'text-aux-muted hover:text-aux-text' }}">
                                <x-icon name="repeat" class="w-4 h-4" />
                                @if ($room->repeat_mode === 'track')
                                    <span class="absolute -top-1.5 -right-1.5 w-3 h-3 rounded-full bg-aux-accent text-black text-[8px] font-bold flex items-center justify-center">1</span>
                                @endif
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
        @php
            $queuedItems = $this->queue->where('is_queued', true)->values();
            $playlistItems = $this->queue->where('is_queued', false)->values();
        @endphp
        <div class="p-6 rounded-xl bg-aux-card border border-aux-border">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h3 class="font-semibold">Up Next</h3>
                    <span class="px-2 py-0.5 rounded-full bg-aux-accent-soft text-aux-accent text-[11px] font-semibold">{{ $this->queuedCount }} tracks</span>
                </div>
                <button type="button" @click="$refs.queueSearch.focus()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-aux-card-hover text-xs font-medium">
                    <x-icon name="plus" class="w-3.5 h-3.5" /> Add a song
                </button>
            </div>

            <ul class="mt-4 divide-y divide-white/5">
                @forelse ($queuedItems as $i => $item)
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
                            <p class="truncate text-xs text-aux-faint">{{ $item->artist }}{{ $item->added_by_name ? ' · added by '.$item->added_by_name : '' }}</p>
                        </div>
                        <span class="shrink-0 text-xs text-aux-faint">{{ gmdate('i:s', intdiv($item->duration_ms, 1000)) }}</span>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-aux-faint">Queue is empty. Add the first track.</li>
                @endforelse
            </ul>

            @if ($room->shuffle_enabled && $playlistItems->isNotEmpty())
                <p class="mt-5 pt-4 border-t border-aux-border text-[11px] text-aux-faint">
                    <x-icon name="shuffle" class="w-3 h-3 inline -mt-0.5" />
                    Shuffle is on, so Spotify doesn't report the real shuffled order. Upcoming tracks from the playlist can't be shown reliably here, but songs queued above are unaffected.
                </p>
            @elseif ($playlistItems->isNotEmpty())
                <p class="mt-5 pt-4 border-t border-aux-border text-[10px] uppercase tracking-widest text-aux-faint">
                    Coming up from the playlist
                </p>
                <ul class="mt-2 divide-y divide-white/5">
                    @foreach ($playlistItems as $item)
                        <li class="group py-2.5 flex items-center gap-3 opacity-60 hover:opacity-100 {{ $this->canGuest('guests_can_manage_playlist') ? 'cursor-pointer' : '' }}"
                            @if ($this->canGuest('guests_can_manage_playlist'))
                                wire:click="playFromPlaylist('{{ $item->spotify_track_id }}')"
                            @endif>
                            <div class="relative w-8 h-8 rounded-md bg-aux-card-hover flex items-center justify-center shrink-0 overflow-hidden">
                                @if ($item->album_art_url)
                                    <img src="{{ $item->album_art_url }}" class="w-full h-full object-cover" alt="">
                                @else
                                    <x-icon name="note" class="w-3.5 h-3.5 text-aux-faint" />
                                @endif
                                @if ($this->canGuest('guests_can_manage_playlist'))
                                    <span class="absolute inset-0 hidden group-hover:flex items-center justify-center bg-black/50">
                                        <x-icon name="play" class="w-3.5 h-3.5 text-white" />
                                    </span>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-xs font-medium">{{ $item->name }}</p>
                                <p class="truncate text-[11px] text-aux-faint">{{ $item->artist }}</p>
                            </div>
                            <span class="shrink-0 text-[11px] text-aux-faint">{{ gmdate('i:s', intdiv($item->duration_ms, 1000)) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="space-y-6">

        {{-- Find music --}}
        @php
            $outOfRange = ! $this->isHost && $room->location_enforced && ! $this->member->passesLocationCheck();
        @endphp
        <div class="p-5 rounded-xl bg-aux-card border border-aux-border">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold">Find Music</h3>
                @if ($outOfRange)
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-400">OUT OF RANGE</span>
                @else
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $room->guests_can_add_to_queue ? 'bg-aux-accent-soft text-aux-accent' : 'bg-red-500/10 text-red-400' }}">
                        {{ $room->guests_can_add_to_queue ? 'AUX OPEN' : 'QUEUE LOCKED' }}
                    </span>
                @endif
            </div>

            @if ($this->isMock)
                <p class="mt-3 text-sm text-aux-faint">
                    {{ $this->isHost ? 'Connect Spotify in Room Settings to search for real songs.' : "The host hasn't connected Spotify yet. Song search isn't available." }}
                </p>
            @elseif ($outOfRange)
                <p class="mt-3 text-sm text-amber-400">You're outside the room's range. Move closer to add songs.</p>
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
                            {{ $this->canGuest('guests_can_add_to_queue') ? 'Search for a song to add it to the queue.' : 'The host has locked the queue. No new songs can be added right now.' }}
                        </li>
                    @endforelse
                </ul>
            @endif

            <div class="mt-4 pt-4 border-t border-aux-border grid {{ $room->location_enforced ? 'grid-cols-3' : 'grid-cols-2' }} text-center">
                <div>
                    <p class="text-lg font-bold text-aux-accent">{{ $this->onlineCount }}</p>
                    <p class="text-[10px] uppercase tracking-wide text-aux-faint">Online</p>
                </div>
                <div>
                    <p class="text-lg font-bold">{{ $this->queuedCount }}</p>
                    <p class="text-[10px] uppercase tracking-wide text-aux-faint">Queued</p>
                </div>
                @if ($room->location_enforced)
                    <div>
                        <p class="text-lg font-bold">{{ $room->location_radius_m }} m</p>
                        <p class="text-[10px] uppercase tracking-wide text-aux-faint">Control radius</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Playlist --}}
        @if ($this->isMock)
            <div class="p-5 rounded-xl bg-aux-card border border-aux-border">
                <h3 class="font-semibold">Playlist</h3>
                <p class="mt-1 text-xs text-aux-faint">Pick a playlist and it plays immediately, just like in Spotify.</p>
                <p class="mt-3 text-sm text-aux-faint">
                    {{ $this->isHost ? 'Connect Spotify in Room Settings to play a playlist.' : "The host hasn't connected Spotify yet. Playlists aren't available." }}
                </p>
            </div>
        @elseif ($this->canGuest('guests_can_manage_playlist'))
            <div class="p-5 rounded-xl bg-aux-card border border-aux-border">
                <h3 class="font-semibold">Playlist</h3>
                <p class="mt-1 text-xs text-aux-faint">Pick a playlist and it plays immediately, just like in Spotify. Added songs play next without interrupting it.</p>

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
                                    <p class="truncate text-xs text-aux-faint">{{ $p['owner'] }}{{ $p['track_count'] !== null ? ' · '.$p['track_count'].' tracks' : '' }}</p>
                                </div>
                                <button wire:click="playPlaylist({{ $i }})"
                                        class="shrink-0 px-2.5 py-1 rounded-full bg-aux-accent text-black text-xs font-semibold">
                                    Play
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        {{-- Emergency stop: locks queue additions instantly --}}
        @if ($this->isHost)
            <div class="p-5 rounded-xl bg-red-500/10 border border-red-500/30">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="zap" class="w-4 h-4 text-red-400" />
                        <h3 class="font-semibold">Emergency stop</h3>
                    </div>
                    @unless ($room->guests_can_add_to_queue)
                        <span class="px-2 py-0.5 rounded-full bg-red-500 text-white text-[11px] font-semibold">QUEUE LOCKED</span>
                    @endunless
                </div>
                <p class="mt-1 text-xs text-aux-muted">
                    Instantly stop everyone from adding songs, overriding individual permissions. Use this if something inappropriate gets added.
                </p>
                @if ($room->guests_can_add_to_queue)
                    <button wire:click="emergencyStopQueue" class="mt-3 w-full py-2.5 rounded-full bg-red-500 text-white text-sm font-semibold hover:bg-red-400">
                        Lock the queue now
                    </button>
                @else
                    <button wire:click="reopenQueue" class="mt-3 w-full py-2.5 rounded-full bg-red-900 text-white text-sm font-semibold hover:bg-red-800">
                        Unlock the queue
                    </button>
                @endif
            </div>
        @endif
    </div>
</div>
