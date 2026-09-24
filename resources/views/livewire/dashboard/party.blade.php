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
            const request = document.fullscreenElement
                ? document.exitFullscreen()
                : document.documentElement.requestFullscreen();

            // Browsers can refuse this (permissions policy, an already-pending
            // request, etc.) — catch it so a rejected promise doesn't surface
            // as an unrelated-looking console error with no indication it
            // came from this button.
            request.catch((e) => console.warn('Fullscreen toggle failed:', e));
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

    <x-ambient-background />

    {{-- No pointer-events-none here: the only thing that brings faded
         controls back is a mousemove, so if the cursor is already resting on
         a button and the user just clicks (no movement first), a
         pointer-events-none control would swallow that click entirely —
         it's still "invisible" at that instant, and passes the click
         through to whatever's behind it instead of the button. Fading via
         opacity alone keeps them clickable at their known position even
         while faded. --}}
    {{-- z-20, not z-10: the centered content block below is also z-10 and
         comes later in the DOM, so on a z-index tie it painted on top of
         these corner controls and silently ate every click aimed at them —
         regardless of the idle-timer/opacity state above. --}}
    <div class="absolute top-6 right-6 z-20 flex items-center gap-4 transition-opacity duration-300"
         :class="controlsVisible ? 'opacity-100' : 'opacity-0'">
        {{-- Fullscreen API isn't supported on mobile browsers (iOS Safari
             doesn't implement it at all), so the button is desktop-only. --}}
        <button x-on:click="toggleFullscreen()" class="hidden sm:block text-aux-faint hover:text-aux-text">
            <x-icon name="expand" class="w-6 h-6" x-show="! isFullscreen" />
            <x-icon name="collapse" class="w-6 h-6" x-show="isFullscreen" x-cloak />
        </button>
        <button onclick="window.close()" class="text-aux-faint hover:text-aux-text">
            <x-icon name="x-mark" class="w-6 h-6" />
        </button>
    </div>

    {{-- Listener count moves to the opposite corner on mobile, where the
         QR/invite block is a popup trigger instead of sitting inline. --}}
    <div class="sm:hidden absolute top-6 left-6 z-20">
        <span class="inline-flex items-center gap-1.5 text-[11px] text-aux-faint">
            <x-icon name="users" class="w-3.5 h-3.5" /> {{ $this->memberCount }}
        </span>
    </div>

    <div class="relative z-10 flex-1 flex flex-col items-center justify-center text-center px-8 py-16">
        <div class="w-72 h-72 lg:w-96 lg:h-96 xl:w-[28rem] xl:h-[28rem] max-w-full aspect-square rounded-2xl bg-gradient-to-br from-aux-card to-aux-bg border border-aux-border flex items-center justify-center overflow-hidden shadow-2xl shadow-aux-accent/10">
            @if ($this->nowPlaying?->album_art_url)
                <img src="{{ $this->nowPlaying->album_art_url }}" class="w-full h-full object-cover" alt="">
            @else
                <x-icon name="note" class="w-16 h-16 lg:w-20 lg:h-20 text-aux-faint" />
            @endif
        </div>

        <p class="mt-8 text-xs lg:text-sm font-semibold uppercase tracking-widest text-aux-accent">
            {{ $this->nowPlaying ? 'Currently spinning' : 'Waiting for the first track' }}
        </p>
        <h1 class="mt-2 text-4xl sm:text-5xl lg:text-6xl xl:text-7xl font-bold max-w-3xl lg:max-w-5xl">{{ $this->nowPlaying->name ?? 'AuxRoom' }}</h1>
        <p class="mt-3 text-xl lg:text-2xl xl:text-3xl text-aux-muted">{{ $this->nowPlaying->artist ?? 'Scan the code to join and add a song' }}</p>

        @if ($this->nowPlaying)
            <span class="mt-4 inline-flex px-2.5 py-1 lg:px-3 lg:py-1.5 rounded-full bg-white/5 text-[11px] lg:text-sm font-semibold text-aux-muted">
                {{ $room->is_playing ? 'PLAYING' : 'PAUSED' }}
            </span>

            <div class="mt-6 w-full max-w-sm lg:max-w-lg xl:max-w-xl"
                 x-data="playbackClock()"
                 x-init="sync({ positionMs: {{ $this->currentPositionMs }}, durationMs: {{ $this->nowPlaying->duration_ms ?? 0 }}, isPlaying: {{ $room->is_playing ? 'true' : 'false' }} })"
                 x-on:playback-sync.window="sync($event.detail)">
                <div class="h-1.5 lg:h-2 bg-white/10 rounded-full overflow-hidden">
                    <div class="h-full bg-aux-accent" :style="`width: ${seekPct}%`"></div>
                </div>
                <div class="mt-2 flex justify-between text-[11px] lg:text-sm text-aux-faint">
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
            <div class="mt-12 w-full max-w-2xl lg:max-w-4xl">
                <p class="flex items-center justify-center gap-2 text-xs lg:text-sm font-semibold uppercase tracking-widest text-aux-accent">
                    <x-icon name="queue-list" class="w-4 h-4" /> Up next
                </p>
                <div class="mt-4 flex flex-wrap justify-center gap-3 lg:gap-4">
                    @foreach ($this->upNext as $item)
                        <div class="flex items-center gap-3 pl-2 pr-4 py-2 lg:pl-3 lg:pr-5 lg:py-3 rounded-xl bg-aux-card border border-aux-border">
                            <div class="w-9 h-9 lg:w-12 lg:h-12 rounded-md bg-aux-card-hover flex items-center justify-center shrink-0 overflow-hidden">
                                @if ($item->album_art_url)
                                    <img src="{{ $item->album_art_url }}" class="w-full h-full object-cover" alt="">
                                @else
                                    <x-icon name="note" class="w-4 h-4 text-aux-faint" />
                                @endif
                            </div>
                            <div class="min-w-0 text-left">
                                <p class="text-sm lg:text-base font-medium truncate max-w-[10rem] lg:max-w-[14rem]">{{ $item->name }}</p>
                                <p class="text-xs lg:text-sm text-aux-faint truncate max-w-[10rem] lg:max-w-[14rem]">{{ $item->artist }}</p>
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
