/**
 * The room page's two location-related Alpine components: the guest-facing
 * "you must be nearby" verification flow (roomLocation), and the host's
 * map-based boundary picker in Room Settings (locationMap).
 *
 * These used to live inline in show.blade.php because they call Livewire
 * methods via the @this Blade directive, which only compiles inside a
 * Blade-rendered <script> block. Livewire 3's Alpine integration also
 * exposes $wire as a magic property in any Alpine scope nested under a
 * Livewire component — inline or, as here, defined in an external file — so
 * that's used instead, and the components can live as ordinary registered
 * Alpine.data() components alongside playbackClock.
 */

import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

// create.blade.php's room-creation map is a plain inline <script> (it has no
// Livewire component yet at that point in the flow), so it relies on this
// global rather than importing Leaflet itself.
window.L = L;

document.addEventListener('alpine:init', () => {
    Alpine.data('roomLocation', (inviteLink) => ({
        enforced: false,
        verified: true,
        showPrompt: false,
        // idle | locating | checking | out-of-range | denied | unsupported | error
        promptStatus: 'idle',
        recheckTimer: null,
        seenPromptForCurrentRequirement: false,
        lastBoundary: null,

        init() {
            window.addEventListener('location-status', (e) => this.onLocationStatus(e.detail));
        },

        onLocationStatus({ enforced, verified, boundary }) {
            const nowRequired = enforced && ! verified;

            // The host moved/changed the boundary — re-check against it
            // right away instead of waiting for the next scheduled ~30s
            // recheck (up to ~2 minutes with the freshness window on top).
            // Skipped on the very first event, since there's nothing to
            // compare against yet and the normal verify flow handles that.
            if (this.lastBoundary !== null && boundary !== null && boundary !== this.lastBoundary && this.verified) {
                this.recheck();
            }
            this.lastBoundary = boundary;

            if (nowRequired && ! this.seenPromptForCurrentRequirement) {
                // Fires once per "became required" transition — room just
                // got enforced, or a stale verification lapsed — not on
                // every 3s poll while the guest is deciding what to do.
                this.showPrompt = true;
                this.promptStatus = 'idle';
                this.seenPromptForCurrentRequirement = true;
            } else if (nowRequired && this.promptStatus === 'checking') {
                // We just asked the server to verify; still not passing
                // means permission succeeded but they're outside the radius.
                this.promptStatus = 'out-of-range';
            }

            if (! nowRequired) {
                this.seenPromptForCurrentRequirement = false;
                if (verified) {
                    this.showPrompt = false;
                    this.promptStatus = 'idle';
                }
            }

            this.enforced = enforced;
            this.verified = verified;
            this.syncRecheckTimer();
        },

        syncRecheckTimer() {
            const shouldRun = this.enforced && this.verified;

            if (shouldRun && ! this.recheckTimer) {
                this.recheckTimer = setInterval(() => this.recheck(), 30000);
            } else if (! shouldRun && this.recheckTimer) {
                clearInterval(this.recheckTimer);
                this.recheckTimer = null;
            }
        },

        recheck() {
            if (! navigator.geolocation) {
                return;
            }
            navigator.geolocation.getCurrentPosition((pos) => {
                this.$wire.verifyLocation(pos.coords.latitude, pos.coords.longitude, true);
            }, () => {
                // Silent: a single missed re-check doesn't revoke access,
                // the freshness window handles sustained absence.
            }, { timeout: 10000, maximumAge: 20000 });
        },

        openPrompt() {
            this.showPrompt = true;
            this.promptStatus = 'idle';
        },

        closePrompt() {
            this.showPrompt = false;
        },

        requestLocation() {
            if (! navigator.geolocation) {
                this.promptStatus = 'unsupported';
                return;
            }
            this.promptStatus = 'locating';
            navigator.geolocation.getCurrentPosition((pos) => {
                this.promptStatus = 'checking';
                this.$wire.verifyLocation(pos.coords.latitude, pos.coords.longitude);
            }, (err) => {
                this.promptStatus = err.code === err.PERMISSION_DENIED ? 'denied' : 'error';
            }, { enableHighAccuracy: true, timeout: 10000 });
        },

        copyLink() {
            navigator.clipboard.writeText(inviteLink);
        },
    }));

    Alpine.data('locationMap', (initialLat, initialLng, initialRadius) => {
        let map, marker, circle;
        const defaultCenter = [52.3676, 4.9041]; // Amsterdam, used only when no location is set yet

        const pinIcon = L.divIcon({
            className: '',
            html: '<div style="width:16px;height:16px;border-radius:50%;background:#1db954;border:3px solid white;box-shadow:0 1px 4px rgba(0,0,0,.4);"></div>',
            iconSize: [16, 16],
            iconAnchor: [8, 8],
        });

        return {
            lat: initialLat,
            lng: initialLng,
            radius: initialRadius,
            manualLat: '',
            manualLng: '',
            manualOpen: false,
            locating: false,
            locateError: '',

            init() {
                const el = this.$refs.mapEl;
                if (! el) {
                    return;
                }

                // Livewire may re-run this init (e.g. after a wire:click elsewhere triggers a
                // morph) even though this whole subtree is wire:ignore'd, producing a fresh
                // Alpine data object with an empty closure. Rather than fight that, cache the
                // real Leaflet instance on the DOM node itself and reattach to it, resyncing
                // Alpine's reactive state from the map (the actual source of truth) instead of
                // rebuilding it and losing whatever the user had set.
                if (el._auxLeaflet) {
                    ({ map, marker, circle } = el._auxLeaflet);
                    const pos = marker.getLatLng();
                    this.lat = pos.lat;
                    this.lng = pos.lng;
                    this.radius = circle.getRadius();
                } else {
                    const center = this.hasPosition ? [this.lat, this.lng] : defaultCenter;

                    map = L.map(el, { attributionControl: true }).setView(center, this.hasPosition ? 15 : 12);

                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                        maxZoom: 19,
                    }).addTo(map);

                    marker = L.marker(center, { draggable: true, icon: pinIcon, opacity: this.hasPosition ? 1 : 0.001 }).addTo(map);
                    circle = L.circle(center, {
                        radius: this.radius,
                        color: '#1db954',
                        fillColor: '#1db954',
                        weight: 1.5,
                        opacity: this.hasPosition ? 1 : 0,
                        fillOpacity: this.hasPosition ? 0.12 : 0,
                    }).addTo(map);

                    el._auxLeaflet = { map, marker, circle };

                    setTimeout(() => map.invalidateSize(), 50);
                }

                marker.off('dragend').on('dragend', () => {
                    const pos = marker.getLatLng();
                    this.setPosition(pos.lat, pos.lng, false);
                });

                map.off('click').on('click', (e) => this.setPosition(e.latlng.lat, e.latlng.lng, true));

                this.$watch('radius', (value) => {
                    const r = parseInt(value, 10);
                    if (! isNaN(r) && r > 0) {
                        circle.setRadius(r);
                    }
                });
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

            save() {
                if (! this.hasPosition) {
                    this.locateError = 'Set a location first.';
                    return;
                }
                this.$wire.setLocationBoundary(this.lat, this.lng, parseInt(this.radius, 10));
            },

            get hasPosition() {
                return this.lat !== null && this.lng !== null;
            },
        };
    });
});
