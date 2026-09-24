<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'AuxRoom') }}</title>

        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        <x-pwa-meta />

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-aux-text antialiased bg-aux-bg">
        <x-ambient-background />

        <div class="relative z-10 min-h-screen flex flex-col justify-center items-center px-6 py-10">
            <div>
                <a href="/" wire:navigate class="flex items-center text-aux-text">
                    <x-logo class="h-8" />
                </a>
            </div>

            <div class="w-full max-w-md mt-6 px-6 py-4 bg-aux-card border border-aux-border rounded-lg overflow-hidden">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
