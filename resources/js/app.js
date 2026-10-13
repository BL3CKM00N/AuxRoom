//

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
import './resume-sync';
import './server-clock';
import './alpine/room-location';
import './alpine/offline-overlay';
import './alpine/live-status';
import './alpine/room-guard';
import './alpine/debug-hud';
import './alpine/qr-scanner';

/**
 * iOS Safari has deliberately ignored the viewport meta tag's
 * user-scalable=no/maximum-scale=1 since iOS 10 (an accessibility override),
 * so pinch-zoom still works there despite it. These are WebKit's own gesture
 * events for a two-finger pinch — preventing them is the actual mechanism
 * needed to stop it on iOS. Single- and multi-touch drags (the seek bar,
 * volume slider, party screen touch controls) are unaffected since those
 * only ever fire touch events, never gesture events.
 */
document.addEventListener('gesturestart', (e) => e.preventDefault());
document.addEventListener('gesturechange', (e) => e.preventDefault());

/**
 * Registered from a stable, unhashed public path (not through Vite) so the
 * browser can compare it byte-for-byte across visits and so its scope
 * covers the whole site. See public/sw.js for what it actually does.
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js');
    });
}

/**
 * Drives the now-playing progress slider smoothly between server syncs.
 * The server only knows position via a wall-clock diff and only pushes it
 * on actions/heartbeats (every few seconds) — without this, the slider
 * visibly jumps instead of advancing continuously.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('playbackClock', () => ({
        positionMs: 0,
        durationMs: 0,
        isPlaying: false,
        // When the song started (or would have, had it played from the beginning), in server time.
        // The position is the server's time now minus that, so it is right on every device, never drifts,
        // and a throttled or backgrounded tab catches up the moment it wakes. Without it (a room that has
        // nothing to say, like the demo) the position counts up from the last one the server sent.
        startedAtMs: null,
        lastTick: 0,
        dragging: false,
        timer: null,

        init() {
            this.lastTick = Date.now();
            this.timer = setInterval(() => this.tick(), 200);
        },

        tick() {
            const now = Date.now();
            const elapsed = now - this.lastTick;
            this.lastTick = now;

            if (!this.isPlaying || this.dragging || this.durationMs <= 0) {
                return;
            }

            this.positionMs = this.startedAtMs !== null
                ? this.fromServerClock()
                : Math.min(this.positionMs + elapsed, this.durationMs);
        },

        fromServerClock() {
            const position = window.serverClock.now() - this.startedAtMs;

            return Math.max(0, Math.min(position, this.durationMs));
        },

        destroy() {
            clearInterval(this.timer);
        },

        sync(detail) {
            if (this.dragging) {
                return;
            }

            this.durationMs = detail.durationMs ?? 0;
            this.isPlaying = detail.isPlaying ?? false;
            this.startedAtMs = detail.startedAtMs ?? null;
            this.positionMs = this.isPlaying && this.startedAtMs !== null ? this.fromServerClock() : (detail.positionMs ?? 0);
            this.lastTick = Date.now();
        },

        get seekPct() {
            return this.durationMs > 0 ? Math.min(100, (this.positionMs / this.durationMs) * 100) : 0;
        },

        formatMs(ms) {
            const totalSeconds = Math.floor(ms / 1000);
            const minutes = Math.floor(totalSeconds / 60);
            const seconds = totalSeconds % 60;

            return `${minutes}:${seconds.toString().padStart(2, '0')}`;
        },
    }));
});


/**
 * A device the account signed out remotely answers an open tab's refresh with
 * a 401 (see ShowRevokedSessionNotice). Go to the login page, which shows why,
 * instead of Livewire's generic error dialog.
 */
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status === 401) {
                preventDefault();
                window.location.assign('/login');
            }
        });
    });
});
