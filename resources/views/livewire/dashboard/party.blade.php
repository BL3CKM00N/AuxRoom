<div wire:poll.5s="poll"
     x-data="{
        isTouch: matchMedia('(pointer: coarse)').matches,
        controlsVisible: true,
        isFullscreen: false,
        showQrPopup: false,
        hideTimer: null,
        resetIdleTimer() {
            // Touch devices have no cursor to mimic and no incidental
            // movement to bring hidden controls back — auto-hiding them
            // there just makes the buttons disappear with no obvious way
            // to get them back, so leave them visible permanently.
            if (this.isTouch) {
                return;
            }

            this.controlsVisible = true;
            clearTimeout(this.hideTimer);
            this.hideTimer = setTimeout(() => { this.controlsVisible = false; }, 5000);
        },
        toggleFullscreen() {
            if (! document.fullscreenElement) {
                document.documentElement.requestFullscreen();
            } else {
                document.exitFullscreen();
            }
        },
     }"
     x-init="
        resetIdleTimer();
        document.addEventListener('fullscreenchange', () => { isFullscreen = !!document.fullscreenElement; });
     "
     x-on:mousemove.window="resetIdleTimer()"
     x-on:touchstart.window="resetIdleTimer()"
     :class="controlsVisible ? '' : 'cursor-none'"
     class="flex-1 bg-aux-bg text-aux-text flex flex-col relative">

    <div class="ambient-glow" aria-hidden="true">
        <div class="ambient-blob ambient-blob-1"></div>
        <div class="ambient-blob ambient-blob-2"></div>
        <div class="ambient-blob ambient-blob-3"></div>
    </div>

    <div class="absolute top-6 right-6 z-10 flex items-center gap-4 transition-opacity duration-300"
         :class="controlsVisible ? 'opacity-100' : 'opacity-0 pointer-events-none'">
        <button x-on:click="toggleFullscreen()" class="text-aux-faint hover:text-aux-text">
            <x-icon name="expand" class="w-6 h-6" x-show="! isFullscreen" />
            <x-icon name="collapse" class="w-6 h-6" x-show="isFullscreen" x-cloak />
        </button>
        <button onclick="window.close()" class="text-aux-faint hover:text-aux-text">
            <x-icon name="x-mark" class="w-6 h-6" />
        </button>
    </div>

    {{-- Listener count moves to the opposite corner on mobile, where the
         QR/invite block is a popup trigger instead of sitting inline. --}}
    <div class="sm:hidden absolute top-6 left-6 z-10">
        <span class="inline-flex items-center gap-1.5 text-[11px] text-aux-faint">
            <x-icon name="users" class="w-3.5 h-3.5" /> {{ $this->memberCount }}
        </span>
    </div>

    <div class="relative z-10 flex-1 flex flex-col items-center justify-center text-center px-8 py-16">
        <div class="w-72 h-72 max-w-full aspect-square rounded-2xl bg-gradient-to-br from-aux-card to-aux-bg border border-aux-border flex items-center justify-center overflow-hidden shadow-2xl shadow-aux-accent/10">
            @if ($this->nowPlaying?->album_art_url)
                <img src="{{ $this->nowPlaying->album_art_url }}" class="w-full h-full object-cover" alt="">
            @else
                <x-icon name="note" class="w-16 h-16 text-aux-faint" />
            @endif
        </div>

        <p class="mt-8 text-xs font-semibold uppercase tracking-widest text-aux-accent">
            {{ $this->nowPlaying ? 'Currently spinning' : 'Waiting for the first track' }}
        </p>
        <h1 class="mt-2 text-4xl sm:text-5xl font-bold max-w-3xl">{{ $this->nowPlaying->name ?? 'AuxRoom' }}</h1>
        <p class="mt-3 text-xl text-aux-muted">{{ $this->nowPlaying->artist ?? 'Scan the code to join and add a song' }}</p>

        @if ($this->nowPlaying)
            <span class="mt-4 inline-flex px-2.5 py-1 rounded-full bg-white/5 text-[11px] font-semibold text-aux-muted">
                {{ $room->is_playing ? 'PLAYING' : 'PAUSED' }}
            </span>

            <div class="mt-6 w-full max-w-sm"
                 x-data="playbackClock()"
                 x-init="sync({ positionMs: {{ $this->currentPositionMs }}, durationMs: {{ $this->nowPlaying->duration_ms ?? 0 }}, isPlaying: {{ $room->is_playing ? 'true' : 'false' }} })"
                 x-on:playback-sync.window="sync($event.detail)">
                <div class="h-1.5 bg-white/10 rounded-full overflow-hidden">
                    <div class="h-full bg-aux-accent" :style="`width: ${seekPct}%`"></div>
                </div>
                <div class="mt-2 flex justify-between text-[11px] text-aux-faint">
                    <span x-text="formatMs(positionMs)"></span>
                    <span x-text="formatMs(durationMs)"></span>
                </div>
            </div>
        @endif

        @if ($room->shuffle_enabled)
            <div class="mt-12">
                <p class="flex items-center justify-center gap-2 text-xs font-semibold uppercase tracking-widest text-aux-faint">
                    <x-icon name="shuffle" class="w-4 h-4" /> Shuffle is on, up next is a surprise
                </p>
            </div>
        @elseif ($this->upNext->isNotEmpty())
            <div class="mt-12 w-full max-w-2xl">
                <p class="flex items-center justify-center gap-2 text-xs font-semibold uppercase tracking-widest text-aux-accent">
                    <x-icon name="queue-list" class="w-4 h-4" /> Up next
                </p>
                <div class="mt-4 flex flex-wrap justify-center gap-3">
                    @foreach ($this->upNext as $item)
                        <div class="flex items-center gap-3 pl-2 pr-4 py-2 rounded-xl bg-aux-card border border-aux-border">
                            <div class="w-9 h-9 rounded-md bg-aux-card-hover flex items-center justify-center shrink-0 overflow-hidden">
                                @if ($item->album_art_url)
                                    <img src="{{ $item->album_art_url }}" class="w-full h-full object-cover" alt="">
                                @else
                                    <x-icon name="note" class="w-4 h-4 text-aux-faint" />
                                @endif
                            </div>
                            <div class="min-w-0 text-left">
                                <p class="text-sm font-medium truncate max-w-[10rem]">{{ $item->name }}</p>
                                <p class="text-xs text-aux-faint truncate max-w-[10rem]">{{ $item->artist }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Mobile: a compact button that opens the QR as a popup, instead
             of a permanent block competing with the centered content for
             limited vertical space. --}}
        <button type="button" x-on:click="showQrPopup = true"
                class="sm:hidden mt-10 inline-flex items-center gap-2 px-4 py-2 rounded-full bg-aux-card border border-aux-border text-sm font-medium">
            <x-icon name="qr" class="w-4 h-4 text-aux-accent" /> Join the aux
        </button>

        {{-- Larger screens: small always-visible QR + code in the corner,
             with room to sit there without colliding with anything. --}}
        <div class="hidden sm:flex sm:absolute sm:bottom-6 sm:right-6 items-end justify-end gap-3">
            <span class="mb-1 inline-flex items-center gap-1.5 text-[11px] text-aux-faint">
                <x-icon name="users" class="w-3.5 h-3.5" /> {{ $this->memberCount }}
            </span>
            <div class="w-[100px] text-center">
                <div class="p-2 bg-white rounded-lg">
                    {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(84)->generate(route('join', ['code' => $room->invite_code])) !!}
                </div>
                <p class="mt-1 text-[11px] font-semibold leading-tight text-aux-faint break-words">{{ $room->invite_code }}</p>
            </div>
        </div>
    </div>

    {{-- Mobile QR popup --}}
    <div x-show="showQrPopup" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-6">
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" x-on:click="showQrPopup = false"></div>
        <div class="relative w-full max-w-xs bg-aux-card border border-aux-border rounded-xl p-6 text-center">
            <button x-on:click="showQrPopup = false" class="absolute top-4 right-4 text-aux-faint hover:text-aux-text">
                <x-icon name="x-mark" class="w-5 h-5" />
            </button>
            <p class="text-xs font-semibold uppercase tracking-widest text-aux-accent">Join the aux</p>
            <div class="mt-4 p-4 bg-white rounded-xl inline-block">
                {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(180)->generate(route('join', ['code' => $room->invite_code])) !!}
            </div>
            <p class="mt-4 text-2xl font-bold tracking-wide">{{ $room->invite_code }}</p>
            <p class="mt-2 text-xs text-aux-faint">Scan to open AuxRoom on your phone.</p>
        </div>
    </div>
</div>
