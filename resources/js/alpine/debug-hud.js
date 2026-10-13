/**
 * A small panel with how accurate this screen is, shown by adding ?debug=1 to a room address. It only reads:
 * the clock offset against the server, the live connection and the pushes, how old the room's data was when the
 * server drew the page, and how far this device's progress bar is from the server's own position.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('debugHud', () => ({
        socket: 'n/a',
        lastSeq: null,
        gaps: 0,
        pushes: 0,
        lastPushAt: null,
        pushToRenderMs: null,
        renderArrivalMs: null,
        verifiedAge: null,
        serverPositionMs: null,
        shownPositionMs: null,
        tickAt: 0,
        timer: null,
        clock: { offset: 0, rtt: null, synced: false },

        init() {
            const pusher = window.Echo?.connector?.pusher;

            if (pusher) {
                this.socket = pusher.connection.state;
                pusher.connection.bind('state_change', ({ current }) => { this.socket = current; });
            }

            // The room's code is in the guard element every room page carries (see room-guard.js).
            const code = document.querySelector('[x-data^="roomGuard"]')?.getAttribute('x-data').match(/'([^']+)'/)?.[1];

            if (code && window.Echo) {
                window.Echo.channel(`room.${code}`).listen('.RoomUpdated', (event) => {
                    this.pushes++;
                    this.lastPushAt = Date.now();

                    if (this.lastSeq !== null && event.seq && event.seq > this.lastSeq + 1) {
                        this.gaps++;
                    }

                    this.lastSeq = event.seq ?? this.lastSeq;
                });
            }

            window.addEventListener('playback-sync', (event) => {
                const d = event.detail || {};

                if (d.serverNowMs) {
                    this.renderArrivalMs = Math.round(window.serverClock.now() - d.serverNowMs);
                }

                this.verifiedAge = d.verifiedAge ?? null;

                if (this.lastPushAt) {
                    this.pushToRenderMs = Date.now() - this.lastPushAt;
                    this.lastPushAt = null;
                }

                this.serverPositionMs = d.startedAtMs ? Math.round(window.serverClock.now() - d.startedAtMs) : (d.positionMs ?? null);
            });

            this.timer = setInterval(() => this.update(), 500);
        },

        update() {
            this.clock = { offset: Math.round(window.serverClock.offset), rtt: window.serverClock.rtt, synced: window.serverClock.synced };

            const el = document.querySelector('[x-data^="playbackClock"]');
            this.shownPositionMs = el && window.Alpine ? Math.round(Alpine.$data(el).positionMs) : null;
        },

        get drift() {
            if (this.serverPositionMs === null || this.shownPositionMs === null) {
                return null;
            }

            const el = document.querySelector('[x-data^="playbackClock"]');
            const data = el ? Alpine.$data(el) : null;

            // The server's position now, from its own start time, against what this device shows right now.
            if (!data || data.startedAtMs === null) {
                return null;
            }

            // Clamped like the bar is: a song that has run past its end shows its end.
            const expected = Math.max(0, Math.min(window.serverClock.now() - data.startedAtMs, data.durationMs));

            return Math.round(expected - this.shownPositionMs);
        },

        destroy() {
            clearInterval(this.timer);
        },
    }));
});
