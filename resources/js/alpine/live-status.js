/**
 * The small "Live" dot in a room. Green: the websocket is connected and the server's background
 * watcher is running, so changes arrive within about a second. Amber: updates still arrive, only
 * slower (the websocket is down, or the watcher is). Gray: no internet connection at all.
 * The watcher part comes from the server with every render (the watcher-status event).
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('liveStatus', (watcherAlive = true) => ({
        online: navigator.onLine,
        socket: 'unavailable',
        watcher: watcherAlive,
        open: false,

        init() {
            window.addEventListener('online', () => { this.online = true; });
            window.addEventListener('offline', () => { this.online = false; });

            const connection = window.Echo?.connector?.pusher?.connection;

            if (connection) {
                this.socket = connection.state;
                connection.bind('state_change', ({ current }) => { this.socket = current; });
            }
        },

        // 'connecting' is the first second of a page load, and a reconnect after sleep: not worth alarming anyone.
        get state() {
            if (!this.online) {
                return 'offline';
            }

            if (this.socket === 'connecting' || this.socket === 'initialized') {
                return 'connecting';
            }

            return this.socket === 'connected' && this.watcher ? 'live' : 'slow';
        },

        get label() {
            return { live: 'Live', connecting: 'Connecting', slow: 'Slower updates', offline: 'Offline' }[this.state];
        },

        get why() {
            if (this.state === 'live') {
                return 'Changes reach this screen within about a second.';
            }

            if (this.state === 'offline') {
                return 'This device has no internet connection.';
            }

            if (this.state === 'connecting') {
                return 'Connecting to the live updates.';
            }

            return this.socket !== 'connected'
                ? 'Live updates are not connected, so this screen checks every few seconds instead. It reconnects by itself.'
                : "The server's background check is not running, so changes can take a few seconds to show. If this stays, tell the host.";
        },
    }));
});
