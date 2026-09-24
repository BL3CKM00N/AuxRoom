<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? 'Room' }} · AuxRoom</title>

        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        <x-pwa-meta />

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100">
        <x-offline-overlay />

        {{-- Mirrors layouts.app's wrapper: the page's own root is flex-1 (not
             min-h-screen), and relies on this flex-col parent to stretch it,
             so nav's fixed height only gets compensated once. padTop is only
             passed by pages that actually render the fixed nav (the guest
             room view) — the Party Screen also uses this layout but has no
             nav and its own fullscreen design, so it opts out. --}}
        <div class="min-h-screen flex flex-col {{ ($padTop ?? false) ? 'pt-16' : '' }}">
            {{ $slot }}
        </div>

        @livewireScripts
    </body>
</html>
