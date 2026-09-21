{{-- Room Settings --}}
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

<div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
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

    {{-- Room name: the room's core identity, goes first --}}
    <div class="w-full p-5 rounded-xl bg-aux-card border border-aux-border flex flex-col">
        <span class="w-9 h-9 rounded-full bg-aux-accent-soft text-aux-accent flex items-center justify-center shrink-0">
            <x-icon name="cog" class="w-4 h-4" />
        </span>
        <p class="mt-3 font-medium text-sm">Room name</p>
        <p class="text-xs text-aux-faint mt-1">What guests see when they join.</p>

        <input type="text" wire:model="editRoomName"
               class="mt-3 w-full rounded-md bg-aux-card-hover border border-aux-border px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-aux-accent">

        <div class="mt-3 pt-3 border-t border-aux-border flex items-center justify-end">
            <button wire:click="saveRoomDetails" class="px-4 py-1.5 rounded-md bg-aux-card-hover hover:bg-white/10 text-xs font-medium">
                Save
            </button>
        </div>
    </div>

    {{-- Location restriction: the toggle plus the radius it enforces --}}
    <div class="w-full p-5 rounded-xl bg-aux-card border border-aux-border flex flex-col">
        <div class="flex items-center justify-between">
            <span class="w-9 h-9 rounded-full flex items-center justify-center shrink-0 {{ $room->location_enforced ? 'bg-aux-accent-soft text-aux-accent' : 'bg-white/5 text-aux-faint' }}">
                <x-icon name="map-pin" class="w-4 h-4" />
            </span>
            <button type="button"
                    wire:click="requestConfirm('toggleLocationEnforced', [], '{{ $room->location_enforced ? 'Turn off location restriction? Guests anywhere will be able to control playback.' : 'Turn on location restriction? Guests outside the radius will be unable to control playback.' }}', '{{ $room->location_enforced ? 'Turn off' : 'Turn on' }}', false)"
                    class="relative inline-flex h-5 w-9 items-center rounded-full transition shrink-0 {{ $room->location_enforced ? 'bg-aux-accent' : 'bg-white/10' }}">
                <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition {{ $room->location_enforced ? 'translate-x-5' : 'translate-x-1' }}"></span>
            </button>
        </div>
        <p class="mt-3 font-medium text-sm">Location restriction</p>
        <p class="text-xs text-aux-faint mt-1">Guests must be within the radius to control playback.</p>

        <div class="mt-3 {{ $room->location_enforced ? '' : 'opacity-40' }}">
            <label class="text-[10px] uppercase tracking-wide text-aux-faint">Radius</label>
            <div class="mt-0.5 relative w-28">
                <input type="number" min="10" max="5000" wire:model="editRadius"
                       class="w-full rounded-md bg-aux-card-hover border border-aux-border pl-3 pr-8 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-aux-accent">
                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-aux-faint">m</span>
            </div>
        </div>

        <div class="mt-3 pt-3 border-t border-aux-border flex items-center justify-end">
            <button wire:click="saveRoomDetails" class="px-4 py-1.5 rounded-md bg-aux-card-hover hover:bg-white/10 text-xs font-medium">
                Save
            </button>
        </div>
    </div>

    {{-- Private room + guest permissions: the two access toggles, paired next to room details --}}
    <button wire:click="requestConfirm('togglePrivate', [], '{{ $room->is_private ? 'Make this room public? Anyone with the code will join instantly, with no approval needed.' : 'Make this room private? New guests will need your approval before they can join.' }}', '{{ $room->is_private ? 'Make public' : 'Make private' }}', false)"
            class="w-full text-left p-5 rounded-xl bg-aux-card border border-aux-border hover:bg-aux-card-hover flex flex-col">
        <div class="flex items-center justify-between">
            <span class="w-9 h-9 rounded-full flex items-center justify-center shrink-0 {{ $room->is_private ? 'bg-aux-accent-soft text-aux-accent' : 'bg-white/5 text-aux-faint' }}">
                <x-icon name="lock" class="w-4 h-4" />
            </span>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $room->is_private ? 'bg-aux-accent-soft text-aux-accent' : 'bg-white/5 text-aux-faint' }}">
                {{ $room->is_private ? 'ON' : 'OFF' }}
            </span>
        </div>
        <p class="mt-3 font-medium text-sm">Private room</p>
        <p class="text-xs text-aux-faint mt-1">Only invited people can see playback data. Host can revoke access at any time.</p>
    </button>

    <button wire:click="setTab('guests')" class="w-full text-left p-5 rounded-xl bg-aux-card border border-aux-border hover:bg-aux-card-hover flex flex-col">
        <div class="flex items-center justify-between">
            <span class="w-9 h-9 rounded-full flex items-center justify-center shrink-0 {{ $room->guests_can_add_to_queue ? 'bg-aux-accent-soft text-aux-accent' : 'bg-red-500/10 text-red-400' }}">
                <x-icon name="users" class="w-4 h-4" />
            </span>
            @unless ($room->guests_can_add_to_queue)
                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-red-500/10 text-red-400">QUEUE LOCKED</span>
            @endunless
        </div>
        <p class="mt-3 font-medium text-sm">Guest permissions</p>
        <p class="text-xs text-aux-faint mt-1">Choose what each guest can do. Play, pause, skip, seek, volume, add songs. Per guest, plus the emergency stop.</p>
        <span class="mt-3 inline-flex text-xs font-medium text-aux-accent items-center gap-1">
            Open Guests <x-icon name="chevron-right" class="w-3 h-3" />
        </span>
    </button>

    {{-- Playback device --}}
    <div class="w-full p-5 rounded-xl bg-aux-card border border-aux-border flex flex-col" x-data="{ open: false }">
        <span class="w-9 h-9 rounded-full bg-aux-accent-soft text-aux-accent flex items-center justify-center shrink-0">
            <x-icon name="speaker" class="w-4 h-4" />
        </span>
        <p class="mt-3 font-medium text-sm">Playback device</p>
        <p class="text-xs text-aux-faint mt-1">One shared soundtrack</p>
        <p class="text-sm mt-2">{{ $room->playbackProvider?->spotifyAccount?->active_device_name ?? 'Living room speaker' }}</p>
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
    <div class="w-full p-5 rounded-xl bg-aux-card border border-aux-border flex flex-col">
        <div class="flex items-center justify-between">
            <span class="w-9 h-9 rounded-full flex items-center justify-center shrink-0 {{ $room->playbackProvider?->hasSpotifyConnected() ? 'bg-aux-accent-soft text-aux-accent' : 'bg-white/5 text-aux-faint' }}">
                <x-icon name="shield" class="w-4 h-4" />
            </span>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $room->playbackProvider?->hasSpotifyConnected() ? 'bg-aux-accent-soft text-aux-accent' : 'bg-white/5 text-aux-faint' }}">
                {{ $room->playbackProvider?->hasSpotifyConnected() ? 'CONNECTED' : 'NOT CONNECTED' }}
            </span>
        </div>
        <p class="mt-3 font-medium text-sm">Spotify connection</p>
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
