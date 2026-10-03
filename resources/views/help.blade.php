<!DOCTYPE html>
<html lang="en" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Help with errors · AuxRoom</title>
        <meta name="description" content="What each AuxRoom error means and how to fix it.">
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans text-aux-text antialiased bg-aux-bg">
        <x-ambient-background />

        <div class="relative z-10 max-w-3xl mx-auto px-5 py-10" x-data="{ q: '' }">
            <a href="/" class="inline-flex items-center"><x-logo class="h-7" /></a>

            <h1 class="mt-8 text-3xl font-bold">Help with errors</h1>
            <p class="mt-2 text-aux-muted">What each message means and how to fix it. Every error in the app has a code in square brackets, like <span class="font-mono">[SP-ASLEEP]</span>. Search for it here, or tell the host.</p>

            <input type="search" x-model="q" placeholder="Search by code or words" aria-label="Search the guides"
                   class="mt-6 w-full px-4 py-2.5 rounded-full bg-aux-card/80 border border-aux-border text-sm placeholder:text-aux-faint focus:outline-none focus:border-aux-accent">

            @foreach ($groups as $prefix => $label)
                @continue(! $grouped->has($prefix))
                <h2 class="mt-10 text-xs font-semibold uppercase tracking-widest text-aux-accent">{{ $label }}</h2>

                <div class="mt-3 space-y-3">
                    @foreach ($grouped[$prefix] as $entry)
                        <section id="{{ strtolower($entry['code']) }}" x-data="{ open: false }"
                                 x-init="open = location.hash === '#{{ strtolower($entry['code']) }}'"
                                 x-on:hashchange.window="if (location.hash === '#{{ strtolower($entry['code']) }}') open = true"
                                 x-show="q.trim() === '' || @js(strtolower($entry['code'].' '.$entry['title'].' '.$entry['what'].' '.$entry['why'])).includes(q.trim().toLowerCase())"
                                 class="rounded-xl bg-aux-card/80 backdrop-blur-md border border-aux-border">
                            <button type="button" @click="open = !open" :aria-expanded="open" class="w-full px-5 py-4 flex items-start gap-3 text-left">
                                <span class="shrink-0 mt-0.5 font-mono text-[11px] px-2 py-0.5 rounded-full bg-white/5 text-aux-accent">{{ $entry['code'] }}</span>
                                <span class="flex-1">
                                    <span class="block font-medium">{{ $entry['title'] }}</span>
                                    <span class="block text-sm text-aux-muted">{{ $entry['what'] }}</span>
                                </span>
                                <x-icon name="chevron-up-down" class="w-4 h-4 mt-1 text-aux-faint shrink-0" />
                            </button>

                            <div x-show="open" x-cloak x-collapse class="px-5 pb-5 space-y-4 text-sm text-aux-muted border-t border-aux-border pt-4">
                                <p><span class="font-semibold text-aux-text">Why it happens.</span> {{ $entry['why'] }}</p>

                                <div>
                                    <p class="font-semibold text-aux-text">If you are the host</p>
                                    <ol class="mt-1 list-decimal pl-5 space-y-1">
                                        @foreach ($entry['host'] as $step) <li>{{ $step }}</li> @endforeach
                                    </ol>
                                </div>
                                <div>
                                    <p class="font-semibold text-aux-text">If you are a guest</p>
                                    <ol class="mt-1 list-decimal pl-5 space-y-1">
                                        @foreach ($entry['guest'] as $step) <li>{{ $step }}</li> @endforeach
                                    </ol>
                                </div>
                            </div>
                        </section>
                    @endforeach
                </div>
            @endforeach
        </div>
        {{-- Alpine ships with Livewire's script, which this page needs for the expanders and the search. --}}
        @livewireScripts
    </body>
</html>
