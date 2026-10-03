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
        <x-ambient-background />

        <div class="relative z-10 min-h-screen flex flex-col items-center justify-center px-6 text-center">
            <a href="/" class="flex items-center">
                <x-logo class="h-8" />
            </a>

            {{-- The aurora behind this drifts and parallaxes, so text sitting
                 directly on it would go in and out of contrast depending on
                 where the light happens to be. A solid card underneath keeps
                 it readable no matter what's moving behind it. --}}
            <div class="mt-10 max-w-sm w-full bg-aux-card/80 backdrop-blur-md border border-aux-border rounded-2xl px-8 py-8">
                <p class="text-sm font-semibold uppercase tracking-widest text-aux-accent">Error {{ $code }}</p>
                <h1 class="mt-2 text-2xl sm:text-3xl font-bold">{{ $title }}</h1>
                <p class="mt-3 text-sm text-aux-muted">{{ $message }}</p>

                @php
                    $entry = \App\Support\Errors\ErrorCatalog::find('HTTP-'.$code);
                    $ref = request()->attributes->get('ref');
                    $copy = collect([
                        "AuxRoom error {$code}: {$title}",
                        $ref ? "Reference: {$ref}" : null,
                        'Time: '.now()->utc()->format('Y-m-d H:i:s').' UTC',
                    ])->filter()->implode("\n");
                @endphp

                {{-- Folded away: the page reads as title, message and a way home; the why and the
                     steps are one tap behind "Learn more". A native <details>, because these
                     pages load no Alpine. --}}
                @if ($entry)
                    <details class="group mt-5 text-left">
                        <summary class="cursor-pointer list-none text-center text-sm text-aux-muted hover:text-aux-text select-none">
                            <span class="underline underline-offset-2">Learn more</span>
                            <span class="inline-block transition-transform group-open:rotate-180" aria-hidden="true">&#9662;</span>
                        </summary>
                        <div class="mt-3 text-sm text-aux-muted space-y-2">
                            <p><span class="font-semibold text-aux-text">Why.</span> {{ $entry['why'] }}</p>
                            <p class="font-semibold text-aux-text">What you can try</p>
                            <ol class="list-decimal pl-5 space-y-1">
                                @foreach ($entry['guest'] as $step)
                                    <li>{{ $step }}</li>
                                @endforeach
                            </ol>
                            <p class="pt-1 text-center">
                                <a href="{{ route('help') }}#{{ strtolower($entry['code']) }}" class="underline underline-offset-2 hover:text-aux-text">Full guide</a>
                            </p>
                        </div>
                    </details>
                @endif

                @if ($ref)
                    <p class="mt-4 text-[11px] text-aux-faint">Reference <span class="font-mono">{{ $ref }}</span></p>
                @endif

                <a href="/" class="mt-6 inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-aux-accent text-black text-sm font-semibold hover:bg-aux-accent-strong">
                    Go back home
                </a>

                <div class="mt-4 flex items-center justify-center gap-4 text-xs text-aux-muted">
                    <button type="button" class="underline underline-offset-2 hover:text-aux-text" data-copy="{{ $copy }}"
                            onclick="navigator.clipboard && navigator.clipboard.writeText(this.dataset.copy).then(() => { this.textContent = 'Copied'; })">Copy details</button>
                </div>
            </div>
        </div>

        <script>
            // iOS Safari ignores the viewport meta tag's user-scalable=no; these
            // gesture events are the actual mechanism needed to block pinch-zoom there.
            document.addEventListener('gesturestart', (e) => e.preventDefault());
            document.addEventListener('gesturechange', (e) => e.preventDefault());
        </script>
    </body>
</html>
