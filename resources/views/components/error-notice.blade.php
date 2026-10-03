@props(['message', 'code' => null, 'details' => null, 'isHost' => false])
@php
    $entry = \App\Support\Errors\ErrorCatalog::find($code);
    $steps = $entry ? ($isHost ? $entry['host'] : $entry['guest']) : [];

    // What "Copy details" puts on the clipboard: enough for someone to see what
    // happened, and nothing secret (no tokens, URLs, invite codes or passwords).
    $copy = collect([
        $entry ? "AuxRoom error {$entry['code']}: {$entry['title']}" : 'AuxRoom error',
        'Message: '.$message,
        $details ? 'Details: '.$details : null,
        'Time: '.now()->utc()->format('Y-m-d H:i:s').' UTC',
        'Role: '.($isHost ? 'host' : 'guest'),
    ])->filter()->implode("\n");
@endphp
{{-- A short message, with the explanation and the steps behind a "More info" toggle.
     The open state is Alpine's, so the page's regular refreshes never close it. --}}
<div x-data="{ open: false, copied: false }"
     {{ $attributes->merge(['class' => 'mx-4 sm:mx-6 mt-4 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-xs']) }}>
    <div class="px-4 py-2.5 flex items-start gap-3">
        <span class="flex-1 min-w-0">
            {{ $message }}
            @if ($entry)
                <span class="ml-1 font-mono text-[10px] opacity-70">[{{ $entry['code'] }}]</span>
            @endif
        </span>
        @if ($entry)
            <button type="button" @click="open = !open" :aria-expanded="open" class="shrink-0 underline underline-offset-2 hover:text-red-300">
                <span x-show="!open">More info</span><span x-show="open" x-cloak>Hide</span>
            </button>
        @endif
    </div>

    @if ($entry)
        <div x-show="open" x-cloak x-collapse class="border-t border-red-500/20">
            <div class="px-4 py-3 space-y-3 text-aux-muted">
                <p class="text-aux-text font-medium">{{ $entry['title'] }}</p>
                <p><span class="font-semibold text-aux-text">What happened.</span> {{ $entry['what'] }}</p>
                <p><span class="font-semibold text-aux-text">Why.</span> {{ $entry['why'] }}</p>

                <div>
                    <p class="font-semibold text-aux-text">{{ $isHost ? 'What you can do' : 'What you can do as a guest' }}</p>
                    <ol class="mt-1 list-decimal pl-5 space-y-1">
                        @foreach ($steps as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ol>
                </div>

                <div class="flex flex-wrap items-center gap-3 pt-1">
                    <a href="{{ route('help') }}#{{ strtolower($entry['code']) }}" target="_blank" class="underline underline-offset-2 hover:text-aux-text">Full guide</a>
                    <button type="button" class="px-3 py-1 rounded-full bg-white/5 hover:bg-white/10 text-aux-text"
                            @click="navigator.clipboard?.writeText(@js($copy)).then(() => { copied = true; setTimeout(() => copied = false, 2000); }).catch(() => {})">
                        <span x-show="!copied">Copy details</span><span x-show="copied" x-cloak>Copied</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
