{{-- Now Playing --}}
{{-- Below lg the two columns are transparent (display: contents) so every card is a direct
     child of the grid and can be ordered on its own: now playing, find music, playlist, queue,
     emergency stop. From lg up they are the usual two columns and the order classes do nothing. --}}
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="contents lg:block lg:col-span-2 lg:space-y-6">

        {{-- Now playing --}}
        @php
            // Distinguishes "nothing playing because no Spotify client is
            // open anywhere" from the generic empty-queue state: $this->devices
            // reflects what Spotify Connect currently reports, same signal
            // the device picker in Room Settings uses to hide itself.
            $spotifyDisconnected = $this->spotifyNeedsReconnect;
            // Inactive: Spotify reported no active device since playback last ran,
            // so the stored track is hidden (kept only for resuming).
            $noDeviceAvailable = ! $spotifyDisconnected && ! $this->isMock
                && ($room->playbackInactive() || (! $this->nowPlaying && ! $room->fallbackIsShown() && empty($this->devices)));
            // The playlist ran out with repeat off (Spotify parks on its first
            // track, which isn't shown as current). Device problems take priority.
            $playlistFinished = ! $spotifyDisconnected && ! $noDeviceAvailable && $room->playlistFinished();
            // Mobile: the device and volume panel is closed until the speaker icon is tapped, so
            // the one case where that could cost the host a step (no device to play on) gets a dot.
            $deviceHint = $this->isHost && ! $this->isMock && empty($this->devices);
        @endphp
        <div class="order-1 p-6 rounded-xl bg-gradient-to-br from-aux-card to-aux-bg border border-aux-border" x-data="{ tools: false }">
            <div class="flex flex-col items-center text-center gap-4 sm:flex-row sm:items-start sm:text-left sm:gap-5">
                <div class="w-36 h-36 sm:w-28 sm:h-28 rounded-lg bg-aux-card-hover flex items-center justify-center shrink-0 overflow-hidden">
                    @if ($this->nowPlaying?->album_art_url)
                        <img src="{{ $this->nowPlaying->album_art_url }}" class="w-full h-full object-cover" alt="">
                    @else
                        <x-icon name="note" class="w-8 h-8 text-aux-faint" />
                    @endif
                </div>
                <div class="w-full min-w-0 sm:flex-1">
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-aux-accent">
                        <x-icon name="note" class="w-3.5 h-3.5" /> {{ $spotifyDisconnected ? 'Spotify disconnected' : ($noDeviceAvailable ? 'No device found' : ($playlistFinished ? 'Playlist finished' : ($room->is_playing ? 'Now spinning' : 'On pause'))) }}
                    </span>
                    @if ($room->smartShuffleOn() && ! $spotifyDisconnected && ! $noDeviceAvailable)
                        <span class="ml-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-aux-accent-soft text-aux-accent text-[10px] font-semibold align-middle" title="Spotify shuffles the playlist and mixes in recommended songs">
                            <x-icon name="zap" class="w-3 h-3" /> Smart Shuffle
                        </span>
                    @endif
                    <h2 class="text-2xl font-bold mt-1 truncate">{{ $this->nowPlaying->name ?? ($playlistFinished ? 'End of the playlist' : ($room->fallbackIsShown() ? $room->fallback_playlist_name : ($spotifyDisconnected ? 'Spotify needs to be reconnected' : ($noDeviceAvailable ? 'Nothing\'s playing anywhere' : 'Nothing queued yet')))) }}</h2>
                    <p class="text-aux-muted truncate">{{ $this->nowPlaying->artist ?? ($playlistFinished ? 'Press play to start it again.' : ($room->fallbackIsShown() ? 'Playlist · shuffled' : ($spotifyDisconnected ? ($this->isHost ? 'Reconnect it in Room Settings.' : 'The host needs to reconnect Spotify.') : ($noDeviceAvailable ? 'Open Spotify on a phone, computer, or speaker, then come back here.' : ($this->isMock ? 'Connect Spotify to add a track' : 'Search below to add the first track'))))) }}</p>

                    @if ($this->nowPlaying || $room->fallbackIsShown())
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

                        <div class="mt-3 flex flex-wrap items-center justify-center gap-x-3 gap-y-2 sm:justify-start">
                            @if ($this->isMock)
                                <span class="hidden sm:inline px-2.5 py-1 rounded-full bg-white/5 text-[10px] font-semibold text-aux-muted">SAMPLE TRACK</span>
                            @endif
                            <button wire:click="toggleShuffle" @disabled(! $this->canGuest('guests_can_play_pause'))
                                    class="relative disabled:opacity-30 disabled:cursor-not-allowed {{ $room->shuffle_enabled ? 'text-aux-accent' : 'text-aux-muted hover:text-aux-text' }}">
                                <x-icon name="shuffle" class="w-4 h-4" />
                                @if ($room->smartShuffleOn())
                                    <span class="absolute -top-1.5 -right-1.5 w-3 h-3 rounded-full bg-aux-accent text-black flex items-center justify-center" title="Smart Shuffle"><x-icon name="zap" class="w-2 h-2" /></span>
                                @endif
                            </button>
                            {{-- Text labels match the bottom bar's icon-only convention below sm --}}
                            <button wire:click="previous" @disabled(! $this->canGuest('guests_can_skip'))
                                    class="inline-flex items-center gap-1 text-aux-muted hover:text-aux-text disabled:opacity-30 disabled:cursor-not-allowed">
                                <x-icon name="back" class="w-4 h-4" /> <span class="hidden sm:inline">Previous</span>
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
                                <x-icon name="skip" class="w-4 h-4" /> <span class="hidden sm:inline">Skip track</span>
                            </button>
                            <button wire:click="toggleRepeat" @disabled(! $this->canGuest('guests_can_play_pause'))
                                    class="relative disabled:opacity-30 disabled:cursor-not-allowed {{ $room->repeat_mode !== 'off' ? 'text-aux-accent' : 'text-aux-muted hover:text-aux-text' }}">
                                <x-icon name="repeat" class="w-4 h-4" />
                                @if ($room->repeat_mode === 'track')
                                    <span class="absolute -top-1.5 -right-1.5 w-3 h-3 rounded-full bg-aux-accent text-black text-[8px] font-bold flex items-center justify-center">1</span>
                                @endif
                            </button>
                            <button type="button" @click="tools = !tools" class="md:hidden relative text-aux-muted hover:text-aux-text" :class="tools ? '!text-aux-accent' : ''" aria-label="Device and volume" :aria-expanded="tools">
                                <x-icon name="volume" class="w-4 h-4" />
                                @if ($deviceHint)
                                    <span class="absolute -top-1 -right-1 w-2 h-2 rounded-full bg-amber-400" title="No playback device found"></span>
                                @endif
                            </button>
                        </div>
                    @else
                        <button wire:click="play" @disabled(($this->queue->isEmpty() && ! $room->fallback_playlist_uri && ! $room->now_playing_track_id) || ! $this->canGuest('guests_can_play_pause'))
                                class="mt-3 inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full bg-aux-accent text-black text-sm font-semibold disabled:opacity-30 disabled:cursor-not-allowed">
                            <x-icon name="play" class="w-3.5 h-3.5" /> Play
                        </button>
                        <div class="mt-3 ml-3 inline-flex align-middle">
                            <button type="button" @click="tools = !tools" class="md:hidden relative text-aux-muted hover:text-aux-text" :class="tools ? '!text-aux-accent' : ''" aria-label="Device and volume" :aria-expanded="tools">
                                <x-icon name="volume" class="w-4 h-4" />
                                @if ($deviceHint)
                                    <span class="absolute -top-1 -right-1 w-2 h-2 rounded-full bg-amber-400" title="No playback device found"></span>
                                @endif
                            </button>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Mobile only: what the bottom player bar used to keep behind its volume
                 button (device and volume). That bar is hidden below md now, so they
                 live here, closed until the speaker icon in the controls (or next to
                 Play when nothing is playing, since a host picks the device first) is
                 tapped. The open state is Alpine's `tools` on the card, so the page's
                 regular refreshes never collapse it. --}}
            @php
                $activeDeviceId = $room->playbackProvider?->spotifyAccount?->active_device_id;
                $activeDeviceName = collect($this->devices)->firstWhere('id', $activeDeviceId)['name']
                    ?? $room->playbackProvider?->spotifyAccount?->active_device_name;
            @endphp
            <div x-show="tools" x-cloak x-collapse class="md:hidden mt-5 pt-4 border-t border-aux-border space-y-4">
                @if ($this->isHost)
                    <div x-data="{ open: false }">
                        <button type="button" @click="open = !open" class="w-full flex items-center gap-3 text-left">
                            <x-icon name="device" class="w-5 h-5 text-aux-muted shrink-0" />
                            <span class="min-w-0 flex-1">
                                <span class="block text-[11px] uppercase tracking-wide text-aux-faint">Playing on</span>
                                <span class="block text-sm truncate">{{ $activeDeviceName ?: 'Choose a device' }}</span>
                            </span>
                            <x-icon name="chevron-up-down" class="w-4 h-4 text-aux-faint shrink-0" />
                        </button>
                        <div x-show="open" x-cloak class="mt-2 rounded-lg bg-aux-card-hover border border-aux-border p-1">
                            @forelse ($this->devices as $device)
                                <button type="button" wire:click="selectDevice({{ \Illuminate\Support\Js::from($device['id']) }}, {{ \Illuminate\Support\Js::from($device['name']) }})" @click="open = false; tools = false"
                                        class="w-full text-left px-3 py-3 rounded-md text-sm {{ $activeDeviceId === $device['id'] ? 'bg-aux-accent-soft text-aux-accent' : 'hover:bg-white/5' }}">
                                    {{ $device['name'] }}
                                </button>
                            @empty
                                <p class="px-3 py-2 text-xs text-aux-faint">No devices found. Open Spotify somewhere first.</p>
                            @endforelse
                        </div>
                    </div>
                @endif

                <div x-data="{ volume: {{ $room->volume_percent }} }" x-on:playback-sync.window="volume = $event.detail.volumePercent ?? volume">
                    <div class="flex items-center gap-3 mb-2">
                        <x-icon name="volume" class="w-5 h-5 shrink-0 {{ $this->canGuest('guests_can_set_volume') && $room->volume_supported ? 'text-aux-muted' : 'text-aux-faint opacity-40' }}" />
                        <span class="text-[11px] uppercase tracking-wide text-aux-faint flex-1">Volume{{ $room->volume_supported ? '' : ' (not adjustable on this device)' }}</span>
                        <span class="text-xs text-aux-faint" x-text="volume"></span>
                    </div>
                    <input type="range" min="0" max="100" :value="volume" @disabled(! $this->canGuest('guests_can_set_volume') || ! $room->volume_supported)
                           :style="`background: linear-gradient(to right, #22c55e ${volume}%, rgba(255,255,255,0.12) ${volume}%); background-clip: content-box;`"
                           x-on:input="volume = $event.target.valueAsNumber"
                           wire:change="setVolume($event.target.value)" class="seek-bar w-full disabled:opacity-30">
                </div>
            </div>
        </div>

        {{-- Up next --}}
        @php
            $queuedItems = $this->queue->where('is_queued', true)->values();
            $playlistItems = $this->queue->where('is_queued', false)->values();
            $contextNoun = str_starts_with((string) $room->now_playing_context_uri, 'spotify:album:') ? 'album' : 'playlist';
            // On the last track with repeat off: say so, instead of an empty gap.
            $onLastTrack = $this->nowPlaying && $room->repeat_mode === 'off' && ! $room->shuffle_enabled
                && \App\Models\Room::isPlayableContext($room->now_playing_context_uri) && ! $room->now_playing_queue_item_id;
        @endphp
        <div class="order-4 p-6 rounded-xl bg-aux-card border border-aux-border">
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
                    {{ $room->smartShuffleOn() ? 'Smart Shuffle is on: Spotify shuffles the playlist and mixes in recommended songs that are not in it. It' : 'Shuffle is on, so Spotify' }} doesn't report the real shuffled order. Upcoming tracks from the playlist can't be shown reliably here, but songs queued above are unaffected.
                </p>
            @elseif ($playlistItems->isNotEmpty())
                <p class="mt-5 pt-4 border-t border-aux-border text-[10px] uppercase tracking-widest text-aux-text">
                    Coming up from the {{ $contextNoun }}
                </p>
                <ul class="mt-2 divide-y divide-white/5">
                    @foreach ($playlistItems as $item)
                        <li class="group py-2.5 flex items-center gap-3 {{ $this->canGuest('guests_can_manage_playlist') ? 'cursor-pointer -mx-2 px-2 rounded-lg hover:bg-white/10' : '' }}"
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
                            @if ($this->canGuest('guests_can_manage_playlist'))
                                <x-icon name="play" class="w-3 h-3 text-aux-faint shrink-0 group-hover:text-aux-accent" />
                            @endif
                            <span class="shrink-0 text-[11px] text-aux-faint">{{ gmdate('i:s', intdiv($item->duration_ms, 1000)) }}</span>
                        </li>
                    @endforeach
                </ul>
            @elseif ($playlistFinished || $onLastTrack)
                <p class="mt-5 pt-4 border-t border-aux-border text-[11px] text-aux-faint">
                    {{ $playlistFinished ? "That was the end of the {$contextNoun}. Press play to start it again." : "This is the last track of the {$contextNoun}." }}
                </p>
            @endif
        </div>
    </div>

    <div class="contents lg:block lg:space-y-6">

        {{-- Find music --}}
        @php
            $outOfRange = ! $this->isHost && $room->location_enforced && ! $this->member->passesLocationCheck();
        @endphp
        <div class="order-2 p-5 rounded-xl bg-aux-card border border-aux-border">
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
            <div class="order-3 p-5 rounded-xl bg-aux-card border border-aux-border">
                <h3 class="font-semibold">Playlist</h3>
                <p class="mt-1 text-xs text-aux-faint">Pick a playlist and it plays immediately, just like in Spotify.</p>
                <p class="mt-3 text-sm text-aux-faint">
                    {{ $this->isHost ? 'Connect Spotify in Room Settings to play a playlist.' : "The host hasn't connected Spotify yet. Playlists aren't available." }}
                </p>
            </div>
        @elseif ($this->canGuest('guests_can_manage_playlist'))
            <div class="order-3 p-5 rounded-xl bg-aux-card border border-aux-border">
                <h3 class="font-semibold">Playlist</h3>
                <p class="mt-1 text-xs text-aux-faint">Pick a playlist and it plays immediately, just like in Spotify. Added songs play next without interrupting it.</p>

                <button type="button" wire:click="openPlaylistPicker"
                        class="mt-3 w-full py-2.5 rounded-full bg-aux-card-hover text-sm font-medium hover:bg-white/10 inline-flex items-center justify-center gap-1.5">
                    <x-icon name="queue-list" class="w-4 h-4" /> Choose a playlist
                </button>
            </div>
        @endif

        {{-- Emergency stop: locks queue additions instantly --}}
        @if ($this->isHost)
            <div class="order-5 p-5 rounded-xl bg-red-500/10 border border-red-500/30">
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
