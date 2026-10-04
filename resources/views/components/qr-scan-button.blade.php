@props(['target' => '', 'next' => null, 'mode' => 'invite', 'label' => 'Scan QR code', 'hint' => "Point your camera at the QR code on the host's screen"])
{{-- Scans a room's QR code with the device camera and fills the invite code
     field, instead of typing 18 characters by hand. Hidden where the browser
     has no camera API. See resources/js/alpine/qr-scanner.js for why this is
     in-app rather than leaving it to the system camera (installed PWAs).

     Two iOS Safari lessons are baked into the overlay below:
     - It's teleported to <body>. Inside the form card (overflow-hidden plus
       rounded corners) WebKit mis-clips position:fixed descendants, and a
       video layer in there can render black.
     - The dimmed area around the viewfinder is plain panels, not a
       `box-shadow: 0 0 0 9999px` cutout. That's a ~20,000px layer, past iOS's
       GPU texture limits, and a known way to get a black screen. --}}
<div x-data="qrScanner('{{ $target }}', @js($next), @js($mode))" x-show="supported" x-cloak
     @keydown.escape.window="open && close()" @pagehide.window="close()">
    <button type="button" @click="start()"
            class="w-full inline-flex items-center justify-center gap-2 py-2 rounded-full border border-aux-border text-sm font-medium text-aux-text hover:bg-aux-card-hover">
        <x-icon name="qr" class="w-4 h-4" /> {{ $label }}
    </button>

    <template x-teleport="body">
        <div x-show="open" x-cloak
             class="fixed inset-0 z-50 bg-black flex flex-col" role="dialog" aria-modal="true" aria-label="Scan QR code">
            <div class="flex items-center justify-between px-5 py-4 text-white">
                <p class="text-sm font-medium">Scan the room's QR code</p>
                <button type="button" @click="close()" class="p-2 -mr-2 text-white/80 hover:text-white" aria-label="Close scanner">
                    <x-icon name="x-mark" class="w-6 h-6" />
                </button>
            </div>

            <div class="relative flex-1 min-h-0">
                {{-- The picture people see is this canvas, painted frame by frame
                     from the video. The video is only the frame source, kept
                     tiny: iOS can paint a live camera <video> solid black even
                     while it delivers frames, but never does that to a canvas. --}}
                <canvas x-ref="preview" class="absolute inset-0 w-full h-full"></canvas>
                <video x-ref="video" playsinline webkit-playsinline muted autoplay disablepictureinpicture
                       class="absolute left-0 top-0 w-px h-px pointer-events-none"></video>
                <canvas x-ref="canvas" class="hidden"></canvas>

                <div x-show="status === 'scanning' || status === 'starting'" class="absolute inset-0 flex flex-col pointer-events-none">
                    <div class="flex-1 bg-black/50"></div>
                    <div class="flex">
                        <div class="flex-1 bg-black/50"></div>
                        <div class="rounded-3xl border-2 border-aux-accent" style="width: min(70vw, 16rem); height: min(70vw, 16rem);"></div>
                        <div class="flex-1 bg-black/50"></div>
                    </div>
                    <div class="flex-1 bg-black/50"></div>
                </div>

                <div x-show="['denied', 'unavailable', 'nopicture', 'error'].includes(status)" class="absolute inset-0 flex items-center justify-center px-8 text-center bg-black">
                    <div class="max-w-xs">
                        <p class="text-white text-sm" x-show="status === 'denied'">Camera access is blocked. Allow it for this site in your browser or phone settings, then try again. <a href="{{ route('help') }}#qr-denied" target="_blank" class="underline text-white/70">[QR-DENIED]</a></p>
                        <p class="text-white text-sm" x-show="status === 'unavailable'">No usable camera was found on this device. You can type the code instead. <a href="{{ route('help') }}#qr-unavailable" target="_blank" class="underline text-white/70">[QR-UNAVAILABLE]</a></p>
                        <p class="text-white text-sm" x-show="status === 'nopicture'">The camera opened but isn't showing a picture. Try again, or type the code instead. <a href="{{ route('help') }}#qr-nopicture" target="_blank" class="underline text-white/70">[QR-NOPICTURE]</a></p>
                        <p class="text-white text-sm" x-show="status === 'error'">The camera couldn't be started. You can type the code instead. <a href="{{ route('help') }}#qr-error" target="_blank" class="underline text-white/70">[QR-ERROR]</a></p>
                        <div class="mt-5 flex items-center justify-center gap-3">
                            <button type="button" @click="close()" class="px-4 py-2 rounded-full border border-white/30 text-white text-sm">Type it instead</button>
                            <button type="button" @click="start()" class="px-4 py-2 rounded-full bg-aux-accent text-black text-sm font-semibold">Try again</button>
                        </div>
                    </div>
                </div>
            </div>

            <pre x-show="debug && debugInfo" x-text="debugInfo" class="px-4 py-2 text-[10px] leading-snug text-green-300 bg-black/80 whitespace-pre-wrap break-all"></pre>

            <div class="px-6 py-5 text-center min-h-[4.5rem]">
                <p class="text-sm text-white/80" x-show="status === 'starting'">Starting camera&hellip;</p>
                <p class="text-sm text-white/80" x-show="status === 'scanning' && !notice">{{ $hint }}</p>
                <p class="text-sm text-amber-300" x-show="notice" x-text="notice"></p>
            </div>
        </div>
    </template>
</div>
