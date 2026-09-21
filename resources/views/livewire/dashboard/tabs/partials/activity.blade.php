<div class="p-5 rounded-xl bg-aux-card border border-aux-border">
    <div class="flex items-center gap-2">
        <x-icon name="clock" class="w-4 h-4 text-aux-accent" />
        <h3 class="font-semibold">Room Activity</h3>
    </div>
    <p class="text-xs text-aux-faint mt-1">Playback and queue events from your room.</p>

    <ul class="mt-4 space-y-3 max-h-64 overflow-y-auto">
        @forelse ($this->activity as $event)
            <li class="flex gap-2.5">
                <span class="mt-1.5 w-1.5 h-1.5 rounded-full bg-aux-accent shrink-0"></span>
                <div class="min-w-0">
                    <p class="text-sm">{{ $event->message }}</p>
                    <p class="text-[11px] text-aux-faint">{{ $event->created_at->format('H:i') }}</p>
                </div>
            </li>
        @empty
            <li class="text-sm text-aux-faint">Your room is ready. Play something to start the activity.</li>
        @endforelse
    </ul>

    <button wire:click="exportActivityLog"
            class="mt-4 w-full inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg bg-aux-card-hover text-xs font-medium hover:bg-white/10">
        <x-icon name="download" class="w-4 h-4" /> Export event log
    </button>
</div>
