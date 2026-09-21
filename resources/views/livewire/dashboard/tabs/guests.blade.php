{{-- Guest Portal --}}
<div>
    <p class="text-xs font-semibold uppercase tracking-widest text-aux-accent">Guest Portal</p>
    <h1 class="text-2xl sm:text-3xl font-bold mt-1">Your room. Your people.</h1>
    <p class="mt-2 text-sm text-aux-muted max-w-lg">
        Everyone with an invite can join. Controls stay within your room's location boundary.
    </p>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6">

    @if ($this->isHost)
        <div class="p-5 rounded-xl bg-aux-card border border-aux-border">
            <div class="flex items-center gap-2">
                <x-icon name="users" class="w-4 h-4 text-aux-accent" />
                <h3 class="font-semibold">Guest permissions</h3>
            </div>
            <p class="mt-1 text-xs text-aux-muted">
                Every guest starts with no permissions. Grant each one individually below, per guest. There's no shared default.
            </p>
        </div>
    @endif

    @if ($this->isHost && $this->pendingMembers->isNotEmpty())
        <div class="p-5 rounded-xl bg-aux-accent-soft border border-aux-accent/30">
            <div class="flex items-center gap-2">
                <x-icon name="clock" class="w-4 h-4 text-aux-accent" />
                <h3 class="font-semibold">Join requests</h3>
                <span class="px-2 py-0.5 rounded-full bg-aux-accent text-black text-[11px] font-semibold">{{ $this->pendingMembers->count() }}</span>
            </div>
            <ul class="mt-3 divide-y divide-white/10">
                @foreach ($this->pendingMembers as $p)
                    <li class="py-2.5 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="w-8 h-8 rounded-full bg-aux-accent text-black flex items-center justify-center font-semibold text-sm shrink-0">
                                {{ Str::of($p->display_name)->substr(0, 1)->upper() }}
                            </span>
                            <p class="text-sm font-medium truncate">{{ $p->display_name }}</p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <button wire:click="approveMember({{ $p->id }})" class="px-3 py-1.5 rounded-full bg-aux-accent text-black text-xs font-semibold hover:bg-aux-accent-strong">
                                Approve
                            </button>
                            <button wire:click="requestConfirm('denyMember', [{{ $p->id }}], 'Deny {{ $p->display_name }}\'s request to join?', 'Deny')"
                                    class="px-3 py-1.5 rounded-full border border-aux-border text-xs font-medium hover:bg-white/10">
                                Deny
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="p-5 rounded-xl bg-aux-card border border-aux-border">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <x-icon name="user-group" class="w-4 h-4 text-aux-accent" />
                <h3 class="font-semibold">Room Members</h3>
                <span class="px-2 py-0.5 rounded-full bg-aux-accent-soft text-aux-accent text-[11px] font-semibold">{{ $this->onlineCount }} online</span>
            </div>
            <button wire:click="$refresh" class="text-aux-muted hover:text-aux-text"><x-icon name="refresh" class="w-4 h-4" /></button>
        </div>

        <ul class="mt-4 divide-y divide-white/5">
            @foreach ($this->members as $m)
                <li class="py-3" @if ($this->isHost && ! $m->isHost()) x-data="{ open: false }" @endif>
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="w-9 h-9 rounded-full bg-aux-accent-soft text-aux-accent flex items-center justify-center font-semibold text-sm shrink-0">
                                {{ Str::of($m->display_name)->substr(0, 1)->upper() }}
                            </span>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-medium truncate">{{ $m->id === $this->member->id ? 'You' : $m->display_name }}</p>
                                    @if ($m->isHost())
                                        <span class="px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wide bg-aux-card-hover text-aux-faint">Host</span>
                                    @endif
                                </div>
                                <p class="text-xs text-aux-faint">{{ $m->isHost() ? 'Room owner' : 'Guest' }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="px-2 py-0.5 rounded-full text-[11px] font-medium {{ $m->isOnline() ? 'bg-aux-accent-soft text-aux-accent' : 'bg-white/5 text-aux-faint' }}">
                                {{ $m->isOnline() ? 'Online' : 'Offline' }}
                            </span>
                            @if ($this->isHost && ! $m->isHost())
                                @if ($room->location_enforced && ! $m->location_exempt)
                                    <button wire:click="grantLocationException({{ $m->id }})" class="px-2.5 py-1 rounded-full border border-aux-border text-[11px] font-medium hover:bg-aux-card-hover">
                                        Disable location check
                                    </button>
                                @endif
                                <button @click="open = !open" class="px-2.5 py-1 rounded-full border border-aux-border text-[11px] font-medium hover:bg-aux-card-hover inline-flex items-center gap-1">
                                    Permissions <x-icon name="chevron-right" class="w-3 h-3 transition" x-bind:class="{ 'rotate-90': open }" />
                                </button>
                                <button wire:click="requestConfirm('kickMember', [{{ $m->id }}], 'Remove {{ $m->display_name }} from the room?', 'Remove')"
                                        class="text-[11px] font-medium text-red-400 hover:text-red-300">
                                    Remove
                                </button>
                            @endif
                        </div>
                    </div>

                    @if ($this->isHost && ! $m->isHost())
                        <div x-show="open" x-cloak class="mt-3 ml-12 grid grid-cols-2 sm:grid-cols-3 gap-2">
                            @foreach ([
                                'guests_can_play_pause' => ['can_play_pause', 'Play / pause'],
                                'guests_can_skip' => ['can_skip', 'Skip track'],
                                'guests_can_seek' => ['can_seek', 'Seek / scrub'],
                                'guests_can_set_volume' => ['can_set_volume', 'Change volume'],
                                'guests_can_manage_playlist' => ['can_manage_playlist', 'Play a playlist'],
                                'guests_can_view_activity' => ['can_view_activity', 'View room activity'],
                            ] as $ability => [$column, $label])
                                <button wire:click="setMemberPermission({{ $m->id }}, '{{ $ability }}', {{ $m->{$column} ? 'false' : 'true' }})"
                                        class="flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-lg text-xs {{ $m->{$column} ? 'bg-aux-accent-soft text-aux-accent' : 'bg-white/5 text-aux-faint' }}">
                                    {{ $label }}
                                    <span class="relative inline-flex h-4 w-7 items-center rounded-full transition shrink-0 {{ $m->{$column} ? 'bg-aux-accent' : 'bg-white/10' }}">
                                        <span class="inline-block h-3 w-3 transform rounded-full bg-white transition {{ $m->{$column} ? 'translate-x-3.5' : 'translate-x-0.5' }}"></span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        <p class="mt-4 pt-4 border-t border-aux-border text-xs text-aux-faint">
            Location exceptions apply to this browser session, including when offline. Rejoining needs fresh approval. Approved people can control from anywhere.
        </p>
    </div>

    </div>

    <div class="space-y-6">
        <div class="p-5 rounded-xl bg-aux-card border border-aux-border flex flex-col items-center text-center">
            <div class="p-3 bg-white rounded-lg">
                {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(150)->generate(route('join', ['code' => $room->invite_code])) !!}
            </div>
            <p class="mt-3 text-sm font-medium">Scan to join the room</p>
            <p class="mt-1 text-lg font-bold tracking-wide">{{ $room->invite_code }}</p>
        </div>

        @if ($this->canGuest('guests_can_view_activity'))
            @include('livewire.dashboard.tabs.partials.activity')
        @endif
    </div>
</div>
