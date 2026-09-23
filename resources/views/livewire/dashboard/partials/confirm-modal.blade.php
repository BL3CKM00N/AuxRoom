{{-- Confirm modal — replaces native confirm() dialogs --}}
@if ($confirmAction)
    <div class="fixed inset-0 z-40 flex items-center justify-center px-4">
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" wire:click="confirmNo"></div>
        <div class="relative w-full max-w-sm bg-aux-card border border-aux-border rounded-xl p-6 text-center">
            <span class="inline-flex w-11 h-11 rounded-full items-center justify-center {{ $confirmDanger ? 'bg-red-500/10 text-red-400' : 'bg-aux-accent-soft text-aux-accent' }}">
                <x-icon name="{{ $confirmDanger ? 'exit' : 'zap' }}" class="w-5 h-5" />
            </span>
            <p class="mt-4 text-sm text-aux-text">{{ $confirmMessage }}</p>
            <div class="mt-5 flex items-center gap-3">
                <button wire:click="confirmNo" class="flex-1 py-2 rounded-full border border-aux-border text-sm font-medium hover:bg-aux-card-hover">
                    Cancel
                </button>
                <button wire:click="confirmYes" class="flex-1 py-2 rounded-full text-sm font-semibold {{ $confirmDanger ? 'bg-red-500 text-white hover:bg-red-400' : 'bg-aux-accent text-black hover:bg-aux-accent-strong' }}">
                    {{ $confirmLabel }}
                </button>
            </div>
        </div>
    </div>
@endif
