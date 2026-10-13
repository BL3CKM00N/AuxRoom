@props(['class' => '', 'align' => 'left'])

{{-- See resources/js/alpine/live-status.js. The watcher-status event comes with every render of the room page. --}}
<div x-data="liveStatus(@js(\App\Services\Spotify\WatchState::watcherAlive()))"
     x-on:watcher-status.window="watcher = $event.detail.alive"
     @click.outside="open = false"
     class="relative {{ $class }}">
    <button type="button" @click="open = !open" class="flex items-center gap-1.5 px-2 py-1 rounded-full text-[11px] text-aux-muted hover:bg-white/5" :aria-label="label">
        <span class="w-2 h-2 rounded-full"
              :class="{ 'bg-aux-accent': state === 'live', 'bg-amber-400': state === 'slow', 'bg-aux-faint': state === 'offline' || state === 'connecting' }"></span>
        <span class="hidden sm:inline" x-text="label"></span>
    </button>
    <div x-show="open" x-cloak x-transition.opacity
         class="absolute {{ $align === 'right' ? 'right-0' : 'left-0' }} top-full mt-2 w-64 rounded-lg bg-aux-card-hover border border-aux-border shadow-xl p-3 z-40 text-xs text-aux-muted">
        <p class="font-medium text-aux-text" x-text="label"></p>
        <p class="mt-1" x-text="why"></p>
        <a href="{{ route('help') }}#net-slow" target="_blank" class="mt-2 inline-block underline underline-offset-2">What does this mean?</a>
    </div>
</div>
