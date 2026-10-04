<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-aux-text leading-tight">Log in another device</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-xl mx-auto px-4 sm:px-6">
            <div class="p-6 bg-aux-card shadow sm:rounded-lg">
                {{-- A phone scans and a computer shows; either can do both (the panel has a link to switch). --}}
                <livewire:account.link-device-panel />

                <p class="mt-6 text-xs text-aux-faint">
                    Only approve a device you started yourself, in front of you. Approved devices appear under Signed-in devices on your profile, where you can log them out.
                </p>
            </div>
        </div>
    </div>
</x-app-layout>
