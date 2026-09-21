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

                    <div>
                        <x-input-label for="name" value="Room name" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" required
                            value="{{ old('name') }}" placeholder="Friday game night" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

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

                        <div x-show="locationEnforced" x-cloak class="mt-4 pl-7 space-y-4">
                            <button type="button" id="use-location"
                                    class="inline-flex items-center px-3 py-1.5 bg-aux-card-hover rounded-md text-xs font-medium text-aux-text">
                                Use my current location
                            </button>
                            <span id="location-status" class="ml-2 text-xs text-aux-muted"></span>

                            <input type="hidden" id="location_lat" name="location_lat" value="{{ old('location_lat') }}">
                            <input type="hidden" id="location_lng" name="location_lng" value="{{ old('location_lng') }}">

                            <div class="max-w-xs">
                                <x-input-label for="location_radius_m" value="Radius (meters)" />
                                <x-text-input id="location_radius_m" name="location_radius_m" type="number" min="10" max="5000"
                                    class="mt-1 block w-full" value="{{ old('location_radius_m', 250) }}" />
                            </div>
                        </div>
                    </div>

                    <x-primary-button type="submit">Create room</x-primary-button>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('use-location')?.addEventListener('click', () => {
            const status = document.getElementById('location-status');
            status.textContent = 'Locating…';
            navigator.geolocation.getCurrentPosition((position) => {
                document.getElementById('location_lat').value = position.coords.latitude;
                document.getElementById('location_lng').value = position.coords.longitude;
                status.textContent = 'Location captured.';
            }, () => {
                status.textContent = 'Could not get your location.';
            });
        });
    </script>
</x-app-layout>
