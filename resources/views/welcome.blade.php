<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>AuxRoom</title>

        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="antialiased font-sans bg-aux-bg text-aux-text">
        <div class="relative min-h-screen flex flex-col">
            <header class="max-w-5xl mx-auto w-full px-6 py-8 flex items-center justify-between">
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

            <main class="flex-1 max-w-5xl mx-auto w-full px-6 py-12 grid gap-12 lg:grid-cols-2 items-center">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-aux-accent">Session active, always</p>
                    <h1 class="mt-2 text-4xl sm:text-5xl font-bold leading-tight">
                        Your room.<br>Your soundtrack.
                    </h1>
                    <p class="mt-4 text-aux-muted max-w-md">
                        One shared soundtrack for the room. Everyone gets a turn on the queue,
                        no Spotify account needed to join.
                    </p>

                    <div class="mt-8 flex flex-wrap gap-3">
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

                    <p class="mt-6 text-xs text-aux-faint flex items-center gap-1.5">
                        <x-icon name="lock" class="w-3.5 h-3.5" /> Invite only. Uses your own Spotify account, no shared app, no middleman.
                    </p>
                </div>

                <div class="p-6 bg-aux-card rounded-xl shadow-lg border border-aux-border">
                    <form method="POST" action="{{ route('join.store') }}" class="space-y-4">
                        @csrf
                        <div>
                            <x-input-label for="name" value="Your name" />
                            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" required />
                        </div>
                        <div>
                            <x-input-label for="invite_code" value="Invite code" />
                            <x-text-input id="invite_code" name="invite_code" type="text" class="mt-1 block w-full"
                                placeholder="XXXXXX-XXXXXX-XXXXXX" required />
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
