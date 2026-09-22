<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">

        <title>AuxRoom</title>

        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="antialiased font-sans bg-aux-bg text-aux-text">
        <div class="relative min-h-screen flex flex-col overflow-hidden">
            {{-- Fixed to the viewport and sized/positioned in vmax/%, so it
                 always fills edge-to-edge and scales with the screen instead
                 of drifting off-center or stopping short on large monitors. --}}
            <div class="ambient-glow" aria-hidden="true">
                <div class="ambient-blob ambient-blob-1"></div>
                <div class="ambient-blob ambient-blob-2"></div>
                <div class="ambient-blob ambient-blob-3"></div>
            </div>

            <header class="relative z-10 max-w-5xl mx-auto w-full px-6 py-8 flex items-center justify-between">
                <a href="/" class="flex items-center">
                    <x-logo class="h-6" />
                </a>
                <nav class="flex items-center gap-4 text-sm text-aux-muted">
                    @auth
                        <a href="{{ route('dashboard') }}" wire:navigate class="hover:text-aux-text">Your room</a>
                    @else
                        <a href="{{ route('login') }}" wire:navigate class="hover:text-aux-text">Log in</a>
                        <a href="{{ route('register') }}" wire:navigate class="hover:text-aux-text">Sign up</a>
                    @endauth
                </nav>
            </header>

            <main class="relative z-10 flex-1 max-w-5xl mx-auto w-full px-6 py-12 grid content-center gap-12 lg:grid-cols-2 items-center">
                <div class="text-center lg:text-left">
                    <p class="text-xs font-semibold uppercase tracking-widest text-aux-accent">Session active, always</p>
                    <h1 class="mt-2 text-4xl sm:text-5xl font-bold leading-tight">
                        Your room.<br>Your soundtrack.
                    </h1>
                    <p class="mt-4 text-aux-muted max-w-md mx-auto lg:mx-0">
                        One shared soundtrack for the room. Everyone gets a turn on the queue,
                        no Spotify account needed to join.
                    </p>

                    <div class="mt-8 flex flex-wrap justify-center lg:justify-start gap-3">
                        @auth
                            <a href="{{ route('rooms.create') }}" wire:navigate
                               class="inline-flex items-center px-5 py-2.5 bg-aux-accent text-black rounded-full font-semibold hover:bg-aux-accent-strong">
                                Host a room
                            </a>
                        @else
                            <a href="{{ route('register') }}" wire:navigate
                               class="inline-flex items-center px-5 py-2.5 bg-aux-accent text-black rounded-full font-semibold hover:bg-aux-accent-strong">
                                Host a room
                            </a>
                        @endauth
                        <a href="{{ route('join') }}" wire:navigate
                           class="inline-flex items-center px-5 py-2.5 border border-aux-border rounded-full font-medium hover:bg-aux-card">
                            Join room
                        </a>
                    </div>

                    <p class="mt-6 text-xs text-aux-faint flex items-center justify-center lg:justify-start gap-1.5">
                        <x-icon name="lock" class="w-3.5 h-3.5" /> Invite only. Uses your own Spotify account, no shared app, no middleman.
                    </p>
                </div>

                {{-- Hidden on mobile: the "Join room" button above already covers this,
                     and the full card just adds redundant scroll below the hero. --}}
                <div class="hidden lg:block p-6 bg-aux-card rounded-xl shadow-lg border border-aux-border">
                    @if ($errors->any())
                        <div class="mb-4 p-3 bg-red-500/10 text-red-400 rounded text-sm">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('join.store') }}" class="space-y-4">
                        @csrf
                        <div>
                            <x-input-label for="name" value="Your name" />
                            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" required
                                value="{{ old('name') }}" />
                        </div>
                        <div>
                            <x-input-label for="invite_code" value="Invite code" />
                            <x-text-input id="invite_code" name="invite_code" type="text" class="mt-1 block w-full"
                                placeholder="XXXXXX-XXXXXX-XXXXXX" required value="{{ old('invite_code') }}" />
                        </div>
                        <button type="submit" class="w-full py-2.5 rounded-full bg-aux-accent text-black font-semibold hover:bg-aux-accent-strong">
                            Join room
                        </button>
                    </form>
                </div>
            </main>
        </div>
    </body>
</html>
