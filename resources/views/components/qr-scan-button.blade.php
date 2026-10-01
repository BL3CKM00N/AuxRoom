@props(['target', 'next' => null])
{{-- Scans a room's QR code with the device camera and fills the invite code
     field, instead of typing 18 characters by hand. Hidden where the browser
     has no camera API. See resources/js/alpine/qr-scanner.js for why this is
     in-app rather than leaving it to the system camera (installed PWAs). --}}
<div x-data="qrScanner('{{ $target }}', @js($next))" x-show="supported" x-cloak
     @keydown.escape.window="open && close()" @pagehide.window="close()">
    <button type="button" @click="start()"
            class="w-full inline-flex items-center justify-center gap-2 py-2 rounded-full border border-aux-border text-sm font-medium text-aux-text hover:bg-aux-card-hover">
        <x-icon name="qr" class="w-4 h-4" /> Scan QR code
    </button>

    <div x-show="open" x-cloak x-transition.opacity
         class="fixed inset-0 z-40 bg-black flex flex-col" role="dialog" aria-modal="true" aria-label="Scan QR code">
        <div class="flex items-center justify-between px-5 py-4 text-white">
            <p class="text-sm font-medium">Scan the room's QR code</p>
            <button type="button" @click="close()" class="p-2 -mr-2 text-white/80 hover:text-white" aria-label="Close scanner">
                <x-icon name="x-mark" class="w-6 h-6" />
            </button>
        </div>

        <div class="relative flex-1 min-h-0">
            <video x-ref="video" playsinline muted class="absolute inset-0 w-full h-full object-cover"></video>
            <canvas x-ref="canvas" class="hidden"></canvas>

            <div x-show="status === 'scanning' || status === 'starting'" class="absolute inset-0 flex items-center justify-center pointer-events-none">
                <div class="w-64 h-64 max-w-[70vw] max-h-[70vw] rounded-3xl border-2 border-aux-accent shadow-[0_0_0_9999px_rgba(0,0,0,0.55)]"></div>
            </div>

            <div x-show="['denied', 'unavailable', 'error'].includes(status)" class="absolute inset-0 flex items-center justify-center px-8 text-center">
                <div class="max-w-xs">
                    <p class="text-white text-sm" x-show="status === 'denied'">Camera access is blocked. Allow it for this site in your browser or phone settings, then try again.</p>
                    <p class="text-white text-sm" x-show="status === 'unavailable'">No usable camera was found on this device. You can type the code instead.</p>
                    <p class="text-white text-sm" x-show="status === 'error'">The camera couldn't be started. You can type the code instead.</p>
                    <div class="mt-5 flex items-center justify-center gap-3">
                        <button type="button" @click="close()" class="px-4 py-2 rounded-full border border-white/30 text-white text-sm">Type it instead</button>
                        <button type="button" @click="start()" class="px-4 py-2 rounded-full bg-aux-accent text-black text-sm font-semibold">Try again</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="px-6 py-5 text-center min-h-[4.5rem]">
            <p class="text-sm text-white/80" x-show="status === 'starting'">Starting camera&hellip;</p>
            <p class="text-sm text-white/80" x-show="status === 'scanning' && !notice">Point your camera at the QR code on the host's screen</p>
            <p class="text-sm text-amber-300" x-show="notice" x-text="notice"></p>
        </div>
    </div>
</div>
