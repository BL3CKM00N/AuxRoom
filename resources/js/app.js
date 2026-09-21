//

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';

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
        dragging: false,
        timer: null,

        init() {
            this.timer = setInterval(() => {
                if (this.isPlaying && !this.dragging && this.durationMs > 0) {
                    this.positionMs = Math.min(this.positionMs + 250, this.durationMs);
                }
            }, 250);
        },

        destroy() {
            clearInterval(this.timer);
        },

        sync(detail) {
            if (this.dragging) {
                return;
            }

            this.positionMs = detail.positionMs ?? 0;
            this.durationMs = detail.durationMs ?? 0;
            this.isPlaying = detail.isPlaying ?? false;
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
