@props(['code', 'title', 'message'])
<!DOCTYPE html>
<html lang="en" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">

        <title>{{ $code }} · {{ $title }} · AuxRoom</title>

        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css'])
    </head>
    <body class="font-sans text-aux-text antialiased bg-aux-bg">
        <div class="ambient-glow" aria-hidden="true">
            <div class="ambient-blob ambient-blob-1"></div>
            <div class="ambient-blob ambient-blob-2"></div>
            <div class="ambient-blob ambient-blob-3"></div>
        </div>

        <div class="relative z-10 min-h-screen flex flex-col items-center justify-center px-6 text-center">
            <a href="/" class="flex items-center">
                <x-logo class="h-8" />
            </a>

            <p class="mt-10 text-sm font-semibold uppercase tracking-widest text-aux-accent">Error {{ $code }}</p>
            <h1 class="mt-2 text-2xl sm:text-3xl font-bold">{{ $title }}</h1>
            <p class="mt-3 text-sm text-aux-muted max-w-sm">{{ $message }}</p>

            <a href="/" class="mt-8 inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-aux-accent text-black text-sm font-semibold hover:bg-aux-accent-strong">
                Go back home
            </a>
        </div>
    </body>
</html>
