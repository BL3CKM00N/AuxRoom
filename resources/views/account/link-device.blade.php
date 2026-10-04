<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-aux-text leading-tight">Log in another device</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-xl mx-auto px-4 sm:px-6">
            <div class="p-6 bg-aux-card shadow sm:rounded-lg">
                <ol class="list-decimal pl-5 space-y-2 text-sm text-aux-muted">
                    <li>On the other device, open AuxRoom's login page and press <span class="font-semibold text-aux-text">Log in with your phone</span>.</li>
                    <li>Scan the QR code it shows with the button below.</li>
                    <li>Type the number shown on that screen and approve.</li>
                </ol>

                <div class="mt-6">
                    <x-qr-scan-button mode="login" label="Scan the login code" hint="Point your camera at the QR code on the other device's login page" />
                </div>

                <p class="mt-4 text-xs text-aux-faint">
                    Only approve a device you started yourself, in front of you. Approved devices appear under Signed-in devices on your profile, where you can log them out.
                </p>
            </div>
        </div>
    </div>
</x-app-layout>
