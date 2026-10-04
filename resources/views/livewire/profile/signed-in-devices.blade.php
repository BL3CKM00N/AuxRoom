<?php

use App\Livewire\Actions\Logout;
use App\Services\Auth\UserSessions;
use Livewire\Volt\Component;

new class extends Component
{
    /** Filled just before the "log out everywhere" confirmation opens. */
    public int $deviceCount = 1;

    public function with(UserSessions $sessions): array
    {
        $user = auth()->user();

        return [
            'devices' => $sessions->devices($user, session()->getId()),
            'hasRoom' => (bool) $user->activeHostedRoom(),
        ];
    }

    public function prepareLogoutEverywhere(UserSessions $sessions): void
    {
        $this->deviceCount = max(1, $sessions->live(auth()->user())->count());
    }

    /** Ends every login, closes the room and goes to the home page, which says what happened. */
    public function logoutEverywhere(Logout $logout): void
    {
        $logout->everywhere();

        $this->redirect('/', navigate: true);
    }

    /** Signs one other device out. Never this one (that is the normal Log Out) and never another account's. */
    public function revoke(string $sessionId, UserSessions $sessions): void
    {
        if ($sessionId === session()->getId()) {
            return;
        }

        $sessions->revoke(auth()->user(), $sessionId, 'device');
    }
}; ?>

<section class="space-y-6" wire:poll.20s>
    <header>
        <h2 class="text-lg font-medium text-aux-text">Signed-in devices</h2>
        <p class="mt-1 text-sm text-aux-muted">
            Everywhere you're logged in right now. A device is logged out automatically after 2 hours without use.
        </p>
    </header>

    <ul class="divide-y divide-aux-border">
        @forelse ($devices as $device)
            <li class="py-3 flex items-center gap-3" wire:key="device-{{ $device['id'] }}">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-aux-text truncate">
                        {{ $device['label'] }}
                        @if ($device['current'])
                            <span class="ml-1 px-2 py-0.5 rounded-full bg-aux-accent-soft text-aux-accent text-[10px] font-semibold align-middle">This device</span>
                        @endif
                    </p>
                    <p class="text-xs text-aux-faint">
                        {{ $device['current'] ? 'Active now' : 'Last active '.$device['lastActive']->diffForHumans() }}
                        @if ($device['via'] === 'qr') &middot; Logged in with a QR code @endif
                    </p>
                </div>

                @unless ($device['current'])
                    <button type="button" wire:click="revoke('{{ $device['id'] }}')"
                            wire:confirm="Log out {{ $device['label'] }}? It will be told why next time it is used."
                            class="shrink-0 px-3 py-1.5 rounded-full border border-aux-border text-xs font-medium hover:bg-white/5">
                        Log out this device
                    </button>
                @endunless
            </li>
        @empty
            <li class="py-3 text-sm text-aux-faint">No other devices are signed in.</li>
        @endforelse
    </ul>

    <div class="flex flex-wrap gap-3">
        {{-- These two live here, not in the menus: they are occasional account actions. --}}
        <a href="{{ route('account.link-device') }}" wire:navigate
           class="inline-flex items-center gap-2 px-4 py-2 rounded-full border border-aux-border text-sm font-medium hover:bg-white/5">
            <x-icon name="qr" class="w-4 h-4" /> Log in another device
        </a>

        <button type="button"
                x-data x-on:click="$wire.prepareLogoutEverywhere().then(() => $dispatch('open-modal', 'logout-everywhere-profile'))"
                class="px-4 py-2 rounded-full bg-red-500/10 border border-red-500/30 text-red-400 text-sm font-semibold hover:bg-red-500/20">
            Log out on all devices
        </button>
    </div>

    <x-logout-everywhere-modal name="logout-everywhere-profile" :count="$deviceCount" :has-room="$hasRoom" />
</section>
