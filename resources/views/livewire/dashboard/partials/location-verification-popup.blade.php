{{-- Location verification popup: explains why before the native browser
     permission prompt appears, and handles denied/out-of-range outcomes
     in-app instead of a bare alert(). Driven entirely by Alpine state fed
     from the location-status event dispatched on every render — see
     roomLocation() in resources/js/alpine/room-location.js. --}}
<div x-show="showPrompt" x-cloak class="fixed inset-0 z-40 flex items-center justify-center px-4">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="promptStatus === 'idle' && closePrompt()"></div>
    <div class="relative w-full max-w-sm bg-aux-card border border-aux-border rounded-xl p-6 text-center">
        <span class="inline-flex w-11 h-11 rounded-full items-center justify-center bg-aux-accent-soft text-aux-accent">
            <x-icon name="map-pin" class="w-5 h-5" />
        </span>

        <template x-if="promptStatus === 'idle' || promptStatus === 'locating'">
            <div>
                <p class="mt-4 text-sm text-aux-text">
                    The host requires guests to be nearby to control playback. We'll ask your browser for your location just to check the distance. We don't track or store your movement.
                </p>
                <div class="mt-5 flex items-center gap-3">
                    <button @click="closePrompt()" class="flex-1 py-2 rounded-full border border-aux-border text-sm font-medium hover:bg-aux-card-hover">
                        Not now
                    </button>
                    <button @click="requestLocation()" :disabled="promptStatus === 'locating'"
                            class="flex-1 py-2 rounded-full text-sm font-semibold bg-aux-accent text-black hover:bg-aux-accent-strong disabled:opacity-50">
                        <span x-text="promptStatus === 'locating' ? 'Locating…' : 'Share my location'"></span>
                    </button>
                </div>
            </div>
        </template>

        <template x-if="promptStatus === 'checking'">
            <p class="mt-4 text-sm text-aux-text">Checking your distance&hellip;</p>
        </template>

        <template x-if="promptStatus === 'out-of-range'">
            <div>
                <p class="mt-4 text-sm text-aux-text">You're outside the room's area. Move closer and try again.</p>
                <div class="mt-5 flex items-center gap-3">
                    <button @click="closePrompt()" class="flex-1 py-2 rounded-full border border-aux-border text-sm font-medium hover:bg-aux-card-hover">
                        Close
                    </button>
                    <button @click="requestLocation()" class="flex-1 py-2 rounded-full text-sm font-semibold bg-aux-accent text-black hover:bg-aux-accent-strong">
                        Try again
                    </button>
                </div>
            </div>
        </template>

        <template x-if="promptStatus === 'denied'">
            <div>
                <p class="mt-4 text-sm text-aux-text">Location access is blocked for this site. Enable it from your browser's site settings (usually the padlock or info icon next to the address bar), then try again.</p>
                <div class="mt-5 flex items-center gap-3">
                    <button @click="closePrompt()" class="flex-1 py-2 rounded-full border border-aux-border text-sm font-medium hover:bg-aux-card-hover">
                        Close
                    </button>
                    <button @click="requestLocation()" class="flex-1 py-2 rounded-full text-sm font-semibold bg-aux-accent text-black hover:bg-aux-accent-strong">
                        Try again
                    </button>
                </div>
            </div>
        </template>

        <template x-if="promptStatus === 'unsupported' || promptStatus === 'error'">
            <div>
                <p class="mt-4 text-sm text-aux-text">Couldn't get a location fix. Check your device's location settings and try again.</p>
                <div class="mt-5 flex items-center gap-3">
                    <button @click="closePrompt()" class="flex-1 py-2 rounded-full border border-aux-border text-sm font-medium hover:bg-aux-card-hover">
                        Close
                    </button>
                    <button @click="requestLocation()" class="flex-1 py-2 rounded-full text-sm font-semibold bg-aux-accent text-black hover:bg-aux-accent-strong">
                        Try again
                    </button>
                </div>
            </div>
        </template>
    </div>
</div>
