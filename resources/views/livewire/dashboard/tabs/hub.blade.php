{{-- Host Hub --}}
<div class="p-5 rounded-xl bg-aux-card border border-aux-border flex flex-wrap items-center justify-between gap-4">
    <div class="flex items-center gap-4">
        <span class="w-12 h-12 rounded-full bg-aux-accent-soft text-aux-accent flex items-center justify-center shrink-0">
            <x-icon name="note" class="w-5 h-5" />
        </span>
        <div>
            <div class="flex items-center gap-2">
                <p class="font-semibold">{{ $this->member->display_name }} <span class="text-aux-faint font-normal">You</span></p>
                <span class="px-2 py-0.5 rounded-full bg-aux-card-hover text-[10px] uppercase tracking-wide text-aux-accent font-semibold">
                    {{ $this->isMock ? 'Demo session' : 'Live session' }}
                </span>
            </div>
            <p class="text-xs text-aux-faint mt-0.5">
                {{ $this->nowPlaying->name ?? 'Sample music' }} &middot; {{ $this->isMock ? 'No audio plays' : ($room->is_playing ? 'Playing' : 'Paused') }}
            </p>
        </div>
    </div>

    <div class="flex items-center gap-2" x-data="{ open: false }">
        <span class="flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-aux-card-hover text-xs text-aux-muted">
            <x-icon name="speaker" class="w-3.5 h-3.5 text-aux-accent" />
            {{ $room->playbackProvider?->spotifyAccount?->active_device_name ?? 'Living room speaker' }}
        </span>
        @if ($this->eligibleProviders->count() > 1)
            <div class="relative">
                <button @click="open = !open" class="flex items-center gap-1.5 px-3 py-1.5 rounded-full border border-aux-border text-xs font-medium hover:bg-aux-card-hover">
                    <x-icon name="refresh" class="w-3.5 h-3.5" /> Switch target
                </button>
                <div x-show="open" @click.outside="open = false" x-cloak
                     class="absolute right-0 mt-2 w-56 rounded-lg bg-aux-card-hover border border-aux-border shadow-xl p-1 z-10">
                    @foreach ($this->eligibleProviders as $eligible)
                        <button wire:click="switchProvider({{ $eligible->user_id }})"
                                class="w-full text-left text-sm px-3 py-2 rounded-md hover:bg-white/5 {{ $room->playback_provider_id === $eligible->user_id ? 'text-aux-accent' : '' }}">
                            {{ $eligible->display_name }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>

{{-- Emergency stop: the one control a host may need to reach instantly, kept at the very top --}}
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

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2 p-6 rounded-xl bg-aux-card border border-aux-border">
        <div class="flex items-center justify-between">
            <p class="text-xs font-semibold uppercase tracking-widest text-aux-accent">Session access key</p>
            <span class="px-2.5 py-1 rounded-full bg-aux-card-hover text-[11px] text-aux-muted">{{ $this->onlineCount }} connected</span>
        </div>
        <p id="invite-code" class="mt-3 text-2xl sm:text-3xl font-bold tracking-wide">{{ $room->invite_code }}</p>
        <p class="mt-2 text-sm text-aux-muted">
            Guests can join from any mobile or desktop browser using this permanent code. No Spotify account or app download required.
        </p>
        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button type="button" @click="copyLink()" class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-aux-accent text-black text-sm font-semibold hover:bg-aux-accent-strong">
                <x-icon name="copy" class="w-4 h-4" /> Copy invite link
            </button>
            <a href="{{ route('rooms.party', $room) }}" target="_blank"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-full border border-aux-border text-sm font-medium hover:bg-aux-card-hover">
                <x-icon name="tv" class="w-4 h-4" /> Launch party screen
            </a>
            <button wire:click="requestConfirm('revokeAllAccess', [], 'Remove all guests and issue a new code?', 'Revoke & regenerate')"
                    class="inline-flex items-center gap-2 text-sm font-medium text-red-400 hover:text-red-300">
                <x-icon name="refresh" class="w-4 h-4" /> Revoke &amp; regenerate
            </button>
            <button wire:click="requestConfirm('closeRoom', [], 'Close this room for everyone? This can\'t be undone.', 'Close room')"
                    class="inline-flex items-center gap-2 text-sm font-medium text-red-400 hover:text-red-300">
                <x-icon name="exit" class="w-4 h-4" /> Close room
            </button>
        </div>
    </div>

    <div class="p-6 rounded-xl bg-aux-card border border-aux-border flex flex-col items-center justify-center text-center">
        <div class="p-3 bg-white rounded-lg">
            {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(150)->generate(route('join', ['code' => $room->invite_code])) !!}
        </div>
        <p class="mt-3 text-sm font-medium">Scan to join the room</p>
        <p class="mt-1 text-lg font-bold tracking-wide">{{ $room->invite_code }}</p>
    </div>
</div>

<div class="flex items-center gap-2 pt-2">
    <x-icon name="cog" class="w-4 h-4 text-aux-muted" />
    <h2 class="font-semibold">Room Access &amp; Playback</h2>
</div>

<div class="flex flex-col items-center gap-4">

    {{-- Room details: name + radius, the room's core identity, goes first --}}
    <div class="w-full p-5 rounded-xl bg-aux-card border border-aux-border flex flex-col">
        <x-icon name="cog" class="w-4 h-4 text-aux-accent" />
        <p class="mt-2 font-medium text-sm">Room details</p>

        <div class="mt-2.5 flex gap-2">
            <div class="flex-1 min-w-0">
                <label class="text-[10px] uppercase tracking-wide text-aux-faint">Room name</label>
                <input type="text" wire:model="editRoomName"
                       class="mt-0.5 w-full rounded-md bg-aux-card-hover border border-aux-border px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-aux-accent">
            </div>
            <div class="w-24 shrink-0">
                <label class="text-[10px] uppercase tracking-wide text-aux-faint">Radius (m)</label>
                <input type="number" min="10" max="5000" wire:model="editRadius"
                       class="mt-0.5 w-full rounded-md bg-aux-card-hover border border-aux-border px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-aux-accent">
            </div>
        </div>

        <div class="mt-3 flex items-center justify-between gap-2 py-2 border-t border-aux-border">
            <div>
                <p class="text-xs font-medium">Location restriction</p>
                <p class="text-[11px] text-aux-faint">Guests must be within the radius to control playback.</p>
            </div>
            <button type="button"
                    wire:click="requestConfirm('toggleLocationEnforced', [], '{{ $room->location_enforced ? 'Turn off location restriction? Guests anywhere will be able to control playback.' : 'Turn on location restriction? Guests outside the radius will be unable to control playback.' }}', '{{ $room->location_enforced ? 'Turn off' : 'Turn on' }}', false)"
                    class="relative inline-flex h-5 w-9 items-center rounded-full transition shrink-0 {{ $room->location_enforced ? 'bg-aux-accent' : 'bg-white/10' }}">
                <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition {{ $room->location_enforced ? 'translate-x-5' : 'translate-x-1' }}"></span>
            </button>
        </div>

        <div class="mt-auto pt-2.5 flex items-center justify-end gap-2">
            <button wire:click="saveRoomDetails" class="px-4 py-1.5 rounded-md bg-aux-card-hover hover:bg-white/10 text-xs font-medium">
                Save
            </button>
        </div>
    </div>

    {{-- Private room + guest permissions: the two access toggles, paired next to room details --}}
    <button wire:click="requestConfirm('togglePrivate', [], '{{ $room->is_private ? 'Make this room public? Anyone with the code will join instantly, with no approval needed.' : 'Make this room private? New guests will need your approval before they can join.' }}', '{{ $room->is_private ? 'Make public' : 'Make private' }}', false)"
            class="w-full text-left p-5 rounded-xl bg-aux-card border border-aux-border hover:bg-aux-card-hover">
        <div class="flex items-center justify-between">
            <x-icon name="lock" class="w-4 h-4 text-aux-accent" />
            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $room->is_private ? 'bg-aux-accent-soft text-aux-accent' : 'bg-white/5 text-aux-faint' }}">
                {{ $room->is_private ? 'ON' : 'OFF' }}
            </span>
        </div>
        <p class="mt-2 font-medium text-sm">Private room</p>
        <p class="text-xs text-aux-faint mt-1">Only invited people can see playback data.</p>
        <p class="text-xs text-aux-faint">Host can revoke access at any time.</p>
    </button>

    <button wire:click="setTab('guests')" class="w-full text-left p-5 rounded-xl bg-aux-card border border-aux-border hover:bg-aux-card-hover">
        <div class="flex items-center justify-between">
            <x-icon name="users" class="w-4 h-4 text-aux-accent" />
            @unless ($room->guests_can_add_to_queue)
                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-red-500/10 text-red-400">QUEUE LOCKED</span>
            @endunless
        </div>
        <p class="mt-2 font-medium text-sm">Guest permissions</p>
        <p class="text-xs text-aux-faint mt-1">Choose what each guest can do</p>
        <p class="text-xs text-aux-faint">Play, pause, skip, seek, volume, add songs. Per guest, plus the emergency stop.</p>
        <span class="mt-3 inline-flex text-xs font-medium text-aux-accent items-center gap-1">
            Open Guest Portal <x-icon name="chevron-right" class="w-3 h-3" />
        </span>
    </button>

    {{-- Playback device --}}
    <div class="w-full p-5 rounded-xl bg-aux-card border border-aux-border" x-data="{ open: false }">
        <x-icon name="speaker" class="w-4 h-4 text-aux-accent" />
        <p class="mt-2 font-medium text-sm">Playback device</p>
        <p class="text-xs text-aux-faint mt-1">One shared soundtrack</p>
        <p class="text-sm mt-1">{{ $room->playbackProvider?->spotifyAccount?->active_device_name ?? 'Living room speaker' }}</p>
        @if (! empty($this->devices))
            <button @click="open = !open" class="mt-3 text-xs font-medium text-aux-accent inline-flex items-center gap-1">
                Choose device <x-icon name="chevron-right" class="w-3 h-3" />
            </button>
            <div x-show="open" x-cloak class="mt-2 space-y-1">
                @foreach ($this->devices as $device)
                    <button wire:click="selectDevice('{{ $device['id'] }}', '{{ $device['name'] }}')"
                            class="w-full text-left text-xs px-2 py-1.5 rounded-md {{ ($room->playbackProvider?->spotifyAccount?->active_device_id ?? null) === $device['id'] ? 'bg-aux-accent-soft text-aux-accent' : 'hover:bg-white/5' }}">
                        {{ $device['name'] }}
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Room activity: host always sees it; collapsed by default (see the partial) --}}
    <div class="w-full">
        @include('livewire.dashboard.tabs.partials.activity')
    </div>

    {{-- Spotify connection: goes last, it's setup rather than day-to-day control --}}
    <div class="w-full p-5 rounded-xl bg-aux-card border border-aux-border">
        <x-icon name="shield" class="w-4 h-4 text-aux-accent" />
        <p class="mt-2 font-medium text-sm">Spotify connection</p>
        <p class="text-xs text-aux-faint mt-1">Credentials stay on the server</p>

        @if ($room->playbackProvider?->hasSpotifyConnected())
            <p class="text-sm mt-2">Connected as {{ $room->playbackProvider->spotifyAccount->display_name ?? $room->playbackProvider->name }}</p>
            <form method="POST" action="{{ route('spotify.disconnect') }}" class="mt-3">
                @csrf
                @method('DELETE')
                <button type="submit" class="text-xs font-medium text-red-400 hover:text-red-300">Disconnect Spotify</button>
            </form>
        @else
            <div class="mt-3">
                <x-spotify-connect-form :account="auth()->user()->spotifyAccount" />
            </div>
        @endif
    </div>
</div>
