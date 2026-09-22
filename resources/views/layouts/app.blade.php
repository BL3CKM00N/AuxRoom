<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'AuxRoom') }}</title>

        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-aux-bg text-aux-text">
        {{-- flex flex-col (not just min-h-screen) so <main> can be flex-1 and
             stretch to fill the remaining height: the room dashboard's own
             root relies on that instead of redeclaring min-h-screen itself,
             which previously compounded with this wrapper's min-h-screen and
             left short pages with ~4rem of dead, pointless scroll (nav is
             fixed now, so unlike before that scroll no longer reveals
             anything — it just felt broken). --}}
        <div class="min-h-screen flex flex-col bg-aux-bg pt-16">
            <livewire:layout.navigation />

            <!-- Page Heading -->
            @if (isset($header))
                <header class="bg-aux-sidebar border-b border-aux-border">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif

            <!-- Page Content -->
            <main class="flex-1 flex flex-col">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
