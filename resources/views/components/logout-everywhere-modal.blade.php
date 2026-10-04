@props(['name' => 'logout-everywhere', 'count' => 1, 'hasRoom' => false])
{{-- Confirmation for "Log out on all devices" (on the Profile page). The including Livewire
     component provides logoutEverywhere() and fills $count just before the modal opens. --}}
<x-modal :name="$name" maxWidth="md" centered>
    <div class="p-6">
        <h2 class="text-lg font-semibold text-aux-text">Log out on all devices?</h2>
        <p class="mt-2 text-sm text-aux-muted">
            You're signed in on {{ $count }} {{ \Illuminate\Support\Str::plural('device', $count) }}.
            This logs you out everywhere{{ $hasRoom ? ', closes your room and sends your guests back to the join page' : '' }}.
            You'll need to log in again on each device.
        </p>

        <div class="mt-6 flex justify-end gap-3">
            <button type="button" x-on:click="$dispatch('close')" class="px-4 py-2 rounded-full border border-aux-border text-sm hover:bg-white/5">Cancel</button>
            <button type="button" wire:click="logoutEverywhere" wire:loading.attr="disabled"
                    class="px-4 py-2 rounded-full bg-red-500 text-white text-sm font-semibold hover:bg-red-400 disabled:opacity-50">
                Log out everywhere
            </button>
        </div>
    </div>
</x-modal>
