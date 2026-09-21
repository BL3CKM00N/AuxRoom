<div wire:poll.5s="poll" class="min-h-screen bg-aux-bg text-aux-text flex flex-col">

    <div class="flex items-center gap-3 px-6 py-4 border-b border-aux-border">
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-aux-accent-soft text-aux-accent text-xs font-semibold">
            <x-icon name="zap" class="w-3.5 h-3.5" /> Party room
        </span>
        <span class="text-sm font-medium">{{ $room->name }}</span>
        <span class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-aux-card border border-aux-border text-xs text-aux-muted">
            <x-icon name="speaker" class="w-3.5 h-3.5 text-aux-accent" /> {{ $room->playbackProvider?->spotifyAccount?->active_device_name ?? 'Living room speaker' }}
        </span>
        <span class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-aux-card border border-aux-border text-xs text-aux-muted">
            <x-icon name="users" class="w-3.5 h-3.5" /> {{ $this->memberCount }} listeners
        </span>
        <a href="{{ route('rooms.show', $room) }}" class="ml-auto inline-flex items-center gap-2 px-3 py-1.5 rounded-full border border-aux-border text-xs font-medium hover:bg-aux-card">
            <x-icon name="exit" class="w-3.5 h-3.5" /> Back to room
        </a>
    </div>

    <div class="flex-1 grid lg:grid-cols-2 gap-10 items-center px-8 sm:px-16 py-10">
        <div>
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
            <h1 class="mt-2 text-4xl sm:text-5xl font-bold">{{ $this->nowPlaying->name ?? 'AuxRoom' }}</h1>
            <p class="mt-3 text-xl text-aux-muted">{{ $this->nowPlaying->artist ?? 'Scan the code to join and add a song' }}</p>

            @if ($this->nowPlaying)
                <span class="mt-4 inline-flex px-2.5 py-1 rounded-full bg-white/5 text-[11px] font-semibold text-aux-muted">
                    {{ $room->is_playing ? 'PLAYING' : 'PAUSED' }}
                </span>

                <div class="mt-6 max-w-sm h-1.5 bg-white/10 rounded-full overflow-hidden">
                    <div class="h-full bg-aux-accent" style="width: {{ $this->nowPlaying->duration_ms > 0 ? min(100, ($this->currentPositionMs / $this->nowPlaying->duration_ms) * 100) : 0 }}%"></div>
                </div>
            @endif

            @if ($this->upNext->isNotEmpty())
                <div class="mt-10">
                    <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-widest text-aux-accent">
                        <x-icon name="queue-list" class="w-4 h-4" /> Coming up next
                    </p>
                    <div class="mt-3 flex flex-wrap gap-3">
                        @foreach ($this->upNext as $i => $item)
                            <div class="flex items-center gap-3 pl-2 pr-4 py-2 rounded-xl bg-aux-card border border-aux-border">
                                <div class="w-9 h-9 rounded-md bg-aux-card-hover flex items-center justify-center shrink-0">
                                    <x-icon name="note" class="w-4 h-4 text-aux-faint" />
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium truncate max-w-[10rem]">{{ $item->name }}</p>
                                    <p class="text-xs text-aux-faint truncate max-w-[10rem]">{{ $item->artist }}</p>
                                </div>
                                <span class="text-aux-accent text-sm font-semibold">{{ $i + 1 }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="p-8 rounded-2xl bg-aux-card border border-aux-border text-center max-w-sm mx-auto w-full">
            <p class="flex items-center justify-center gap-2 text-xs font-semibold uppercase tracking-widest text-aux-accent">
                <x-icon name="qr" class="w-4 h-4" /> Join the aux
            </p>
            <div class="mt-5 p-4 bg-white rounded-xl inline-block">
                {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(200)->generate(route('join', ['code' => $room->invite_code])) !!}
            </div>
            <p class="mt-5 text-xs text-aux-faint uppercase tracking-wide">Open the room &middot; enter your invite</p>
            <p class="mt-2 text-2xl font-bold tracking-wide">{{ $room->invite_code }}</p>
            <p class="mt-2 text-xs text-aux-faint">Scan to open AuxRoom on your phone.</p>
        </div>
    </div>
</div>
