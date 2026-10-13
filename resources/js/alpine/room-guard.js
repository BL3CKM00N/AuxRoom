/**
 * Keeps a room page honest about live updates. Two things can go wrong: a push can be lost on the way (the
 * numbers on the pushes show it: one is missing), and the live connection can be down, in which case nothing
 * is coming at all. Either way the page re-reads the room itself, instead of waiting for its slow scheduled check.
 *
 * Placed once in a room page: <div x-data="roomGuard('CODE', 'heartbeat')"></div>
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('roomGuard', (code, method) => ({
        lastSeq: 0,
        socketOk: true,
        watcherOk: true,
        timer: null,

        init() {
            const pusher = window.Echo?.connector?.pusher;

            // The dot in the leading '.' matters: these events have a custom broadcast name.
            window.Echo?.channel(`room.${code}`).listen('.RoomUpdated', (event) => {
                if (event.seq) {
                    if (this.lastSeq && event.seq > this.lastSeq + 1) {
                        this.refresh();
                    }

                    this.lastSeq = Math.max(this.lastSeq, event.seq);
                }
            });

            if (pusher) {
                this.socketOk = pusher.connection.state === 'connected';
                pusher.connection.bind('state_change', ({ current }) => { this.socketOk = current === 'connected'; });
            }

            window.addEventListener('watcher-status', (event) => { this.watcherOk = event.detail.alive; });

            // While live updates are not fully working, look every few seconds; otherwise the page's own slow check is enough.
            this.timer = setInterval(() => {
                if ((!this.socketOk || !this.watcherOk) && !document.hidden) {
                    this.refresh();
                }
            }, 4000);
        },

        refresh() {
            this.$wire[method]();
        },

        destroy() {
            clearInterval(this.timer);
        },
    }));
});
