<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }

    public function with(): array
    {
        return [
            'hostedRoom' => auth()->user()?->activeHostedRoom(),
        ];
    }
}; ?>

<nav x-data="{ open: false }" wire:poll.5s="$refresh" class="bg-aux-sidebar border-b border-aux-border">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center gap-2 text-aux-text">
                    <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2">
                        <span class="text-aux-accent"><x-icon name="zap" class="w-6 h-6" /></span>
                        <span class="font-semibold">AuxRoom</span>
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    @if ($hostedRoom)
                        <x-nav-link :href="route('dashboard', ['tab' => 'hub'])" :active="request()->routeIs('dashboard') && request()->query('tab', 'hub') === 'hub'" wire:navigate>
                            Host Hub
                        </x-nav-link>
                        <x-nav-link :href="route('dashboard', ['tab' => 'queue'])" :active="request()->routeIs('dashboard') && request()->query('tab') === 'queue'" wire:navigate>
                            Collaborative Queue
                        </x-nav-link>
                        <x-nav-link :href="route('dashboard', ['tab' => 'guests'])" :active="request()->routeIs('dashboard') && request()->query('tab') === 'guests'" wire:navigate>
                            <span class="inline-flex items-center gap-1.5">
                                Guest Portal
                                @if ($hostedRoom->pendingMembers()->count() > 0)
                                    <span class="w-5 h-5 rounded-full bg-aux-accent text-black text-[10px] font-bold flex items-center justify-center">{{ $hostedRoom->pendingMembers()->count() }}</span>
                                @endif
                            </span>
                        </x-nav-link>
                        <x-nav-link :href="route('rooms.party', $hostedRoom)" target="_blank">
                            Party Display
                        </x-nav-link>
                    @else
                        <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard') || request()->routeIs('rooms.*')" wire:navigate>
                            {{ __('Dashboard') }}
                        </x-nav-link>
                    @endif
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-aux-muted bg-aux-sidebar hover:text-aux-text focus:outline-none transition ease-in-out duration-150">
                            <div x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile')" wire:navigate>
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <button wire:click="logout" class="w-full text-start">
                            <x-dropdown-link>
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 dark:text-gray-500 hover:text-gray-500 dark:hover:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-900 focus:outline-none focus:bg-gray-100 dark:focus:bg-gray-900 focus:text-gray-500 dark:focus:text-gray-400 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            @if ($hostedRoom)
                <x-responsive-nav-link :href="route('dashboard', ['tab' => 'hub'])" :active="request()->routeIs('dashboard') && request()->query('tab', 'hub') === 'hub'" wire:navigate>
                    Host Hub
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('dashboard', ['tab' => 'queue'])" :active="request()->routeIs('dashboard') && request()->query('tab') === 'queue'" wire:navigate>
                    Collaborative Queue
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('dashboard', ['tab' => 'guests'])" :active="request()->routeIs('dashboard') && request()->query('tab') === 'guests'" wire:navigate>
                    Guest Portal
                    @if ($hostedRoom->pendingMembers()->count() > 0)
                        <span class="ml-1 inline-flex w-4 h-4 rounded-full bg-aux-accent text-black text-[9px] font-bold items-center justify-center">{{ $hostedRoom->pendingMembers()->count() }}</span>
                    @endif
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('rooms.party', $hostedRoom)" target="_blank">
                    Party Display
                </x-responsive-nav-link>
            @else
                <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard') || request()->routeIs('rooms.*')" wire:navigate>
                    {{ __('Dashboard') }}
                </x-responsive-nav-link>
            @endif
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-aux-border">
            <div class="px-4">
                <div class="font-medium text-base text-aux-text" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
                <div class="font-medium text-sm text-aux-muted">{{ auth()->user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile')" wire:navigate>
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <button wire:click="logout" class="w-full text-start">
                    <x-responsive-nav-link>
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </button>
            </div>
        </div>
    </div>
</nav>
