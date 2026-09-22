<x-app-layout>
    <div class="py-12" x-data="{ step: {{ $hasSpotify ? 2 : 1 }} }">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-4 bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-300 rounded-lg text-sm">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('error'))
                <div class="p-4 bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-300 rounded-lg text-sm">
                    {{ session('error') }}
                </div>
            @endif

            {{-- Step 1: connect Spotify (skippable) --}}
            <div x-show="step === 1" x-cloak class="p-6 sm:p-8 bg-aux-card border border-aux-border rounded-lg text-center">
                <span class="inline-flex w-12 h-12 rounded-full bg-aux-accent-soft text-aux-accent items-center justify-center mx-auto">
                    <x-icon name="shield" class="w-5 h-5" />
                </span>
                <h3 class="mt-4 text-lg font-semibold text-aux-text">Connect your Spotify account</h3>
                <p class="mt-2 text-sm text-aux-muted max-w-sm mx-auto">
                    Connect your own Spotify Developer app so your room can play real music
                    and control a real device. You can skip this and set it up later from
                    Room Settings. The room will run in demo mode until then.
                </p>

                <div class="mt-6 max-w-sm mx-auto text-left">
                    <x-spotify-connect-form :account="$account" />
                </div>

                <button type="button" @click="step = 2"
                        class="mt-4 inline-flex items-center px-5 py-2.5 border border-aux-border rounded-full font-medium text-sm hover:bg-aux-card-hover">
                    Skip for now
                </button>
            </div>

            {{-- Step 2: room details --}}
            <div x-show="step === 2" x-cloak>
                @unless ($hasSpotify)
                    <div class="mb-6 p-4 bg-yellow-500/10 border border-yellow-500/20 text-yellow-400 rounded-lg text-sm flex items-center justify-between gap-3">
                        <span>Spotify isn't connected yet. This room will start in demo mode.</span>
                        <button type="button" @click="step = 1" class="shrink-0 underline font-medium">Connect now</button>
                    </div>
                @endunless

                <form method="POST" action="{{ route('rooms.store') }}" x-data="{ locationEnforced: false }"
                      class="p-4 sm:p-8 bg-aux-card border border-aux-border rounded-lg space-y-6">
                    @csrf

                    <div class="flex items-start gap-3">
                        <input type="checkbox" id="is_private" name="is_private" value="1" checked
                               class="mt-1 rounded border-aux-border text-aux-accent bg-aux-card-hover focus:ring-aux-accent">
                        <label for="is_private" class="text-sm text-aux-muted">
                            <span class="font-medium">Private room</span>, only invited people can see playback data.
                        </label>
                    </div>

                    <div class="border-t border-aux-border pt-6">
                        <div class="flex items-start gap-3">
                            <input type="checkbox" id="location_enforced" name="location_enforced" value="1"
                                   x-model="locationEnforced"
                                   class="mt-1 rounded border-aux-border text-aux-accent bg-aux-card-hover focus:ring-aux-accent">
                            <label for="location_enforced" class="text-sm text-aux-muted">
                                <span class="font-medium">Location boundary</span>, require guests to be physically nearby to control playback.
                            </label>
                        </div>

                        <template x-if="locationEnforced">
                            <div class="mt-4 pl-7 space-y-4" x-data="createLocationMap({{ old('location_radius_m', 250) }})">
                                <div class="relative z-0 rounded-lg overflow-hidden border border-aux-border">
                                    <div x-ref="mapEl" class="w-full h-48"></div>
                                </div>

                                <div class="flex items-center gap-2 flex-wrap">
                                    <button type="button" @click="useMyLocation()" :disabled="locating"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-aux-card-hover rounded-md text-xs font-medium text-aux-text disabled:opacity-50">
                                        <x-icon name="locate" class="w-3.5 h-3.5" />
                                        <span x-text="locating ? 'Locating…' : 'Use my current location'"></span>
                                    </button>
                                    <button type="button" @click="manualOpen = !manualOpen"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-aux-card-hover rounded-md text-xs font-medium text-aux-text">
                                        Enter coordinates
                                    </button>
                                </div>

                                <p class="text-xs text-red-400" x-show="locateError" x-text="locateError" x-cloak></p>
                                <p class="text-xs text-aux-muted" x-show="hasPosition" x-cloak>
                                    Pin at <span x-text="lat !== null ? lat.toFixed(5) : ''"></span>, <span x-text="lng !== null ? lng.toFixed(5) : ''"></span>. Click or drag the pin on the map to adjust.
                                </p>
                                <p class="text-xs text-aux-muted" x-show="!hasPosition" x-cloak>
                                    No location set yet. Use your current location, click the map, or enter coordinates below.
                                </p>

                                <div class="grid grid-cols-2 gap-2" x-show="manualOpen" x-cloak x-transition>
                                    <input type="number" step="0.000001" placeholder="Latitude" x-model="manualLat"
                                           class="rounded-md bg-aux-card-hover border border-aux-border px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-aux-accent">
                                    <input type="number" step="0.000001" placeholder="Longitude" x-model="manualLng"
                                           class="rounded-md bg-aux-card-hover border border-aux-border px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-aux-accent">
                                    <button type="button" @click="applyManual()" class="col-span-2 px-3 py-1.5 rounded-md bg-aux-card-hover hover:bg-white/10 text-xs font-medium">
                                        Set pin from coordinates
                                    </button>
                                </div>

                                {{-- disabled (not just empty) so an unset pin is omitted from the
                                     request entirely, matching the nullable server validation,
                                     instead of submitting the literal string "null" --}}
                                <input type="hidden" name="location_lat" :value="lat" :disabled="!hasPosition">
                                <input type="hidden" name="location_lng" :value="lng" :disabled="!hasPosition">

                                <div class="max-w-xs">
                                    <x-input-label for="location_radius_m" value="Radius (meters)" />
                                    <x-text-input id="location_radius_m" name="location_radius_m" type="number" min="10" max="5000"
                                        class="mt-1 block w-full" x-model.number="radius" />
                                </div>
                            </div>
                        </template>
                    </div>

                    <x-primary-button type="submit">Create room</x-primary-button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function createLocationMap(initialRadius) {
            let map, marker, circle;
            const defaultCenter = [52.3676, 4.9041]; // Amsterdam, used only until a location is set

            const pinIcon = L.divIcon({
                className: '',
                html: '<div style="width:16px;height:16px;border-radius:50%;background:#1db954;border:3px solid white;box-shadow:0 1px 4px rgba(0,0,0,.4);"></div>',
                iconSize: [16, 16],
                iconAnchor: [8, 8],
            });

            return {
                lat: null,
                lng: null,
                radius: initialRadius,
                manualLat: '',
                manualLng: '',
                manualOpen: false,
                locating: false,
                locateError: '',

                init() {
                    map = L.map(this.$refs.mapEl, { attributionControl: true }).setView(defaultCenter, 12);

                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                        maxZoom: 19,
                    }).addTo(map);

                    marker = L.marker(defaultCenter, { draggable: true, icon: pinIcon, opacity: 0.001 }).addTo(map);
                    circle = L.circle(defaultCenter, {
                        radius: this.radius,
                        color: '#1db954',
                        fillColor: '#1db954',
                        weight: 1.5,
                        opacity: 0,
                        fillOpacity: 0,
                    }).addTo(map);

                    marker.on('dragend', () => {
                        const pos = marker.getLatLng();
                        this.setPosition(pos.lat, pos.lng, false);
                    });

                    map.on('click', (e) => this.setPosition(e.latlng.lat, e.latlng.lng, true));

                    this.$watch('radius', (value) => {
                        const r = parseInt(value, 10);
                        if (! isNaN(r) && r > 0) {
                            circle.setRadius(r);
                        }
                    });

                    setTimeout(() => map.invalidateSize(), 50);
                },

                setPosition(lat, lng, recenter) {
                    this.lat = lat;
                    this.lng = lng;
                    this.locateError = '';
                    marker.setLatLng([lat, lng]).setOpacity(1);
                    circle.setLatLng([lat, lng]).setStyle({ opacity: 1, fillOpacity: 0.12 });
                    if (recenter) {
                        map.setView([lat, lng], Math.max(map.getZoom(), 15));
                    }
                },

                useMyLocation() {
                    if (! navigator.geolocation) {
                        this.locateError = 'Geolocation is not supported by this browser.';
                        return;
                    }
                    this.locating = true;
                    this.locateError = '';
                    navigator.geolocation.getCurrentPosition((pos) => {
                        this.locating = false;
                        this.setPosition(pos.coords.latitude, pos.coords.longitude, true);
                    }, () => {
                        this.locating = false;
                        this.locateError = 'Could not get your location. Drop the pin on the map or enter coordinates below.';
                    }, { enableHighAccuracy: true, timeout: 10000 });
                },

                applyManual() {
                    const lat = parseFloat(this.manualLat);
                    const lng = parseFloat(this.manualLng);
                    if (isNaN(lat) || isNaN(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
                        this.locateError = 'Enter a valid latitude (-90 to 90) and longitude (-180 to 180).';
                        return;
                    }
                    this.setPosition(lat, lng, true);
                    this.manualLat = '';
                    this.manualLng = '';
                    this.manualOpen = false;
                },

                get hasPosition() {
                    return this.lat !== null && this.lng !== null;
                },
            };
        }
    </script>
</x-app-layout>
