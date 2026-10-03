// A phone freezes a backgrounded PWA: its timers stop (so wire:poll stops) and
// its websocket goes stale without the page knowing. Coming back to the app
// then showed old state until the next poll landed or the socket noticed and
// reconnected, which took several seconds. On the way back to the foreground
// this announces "app-resumed"; the polled components listen for it and
// refresh straight away (see their views), and the live connection is
// restarted if the page was away long enough for it to have gone stale.
const STALE_AFTER_MS = 5000;
const MIN_GAP_MS = 800;

let hiddenAt = document.hidden ? Date.now() : null;
let lastResume = 0;

function resume() {
    const now = Date.now();
    const away = hiddenAt === null ? 0 : now - hiddenAt;
    hiddenAt = null;

    // visibilitychange, pageshow, focus and online can all fire together.
    if (now - lastResume < MIN_GAP_MS) {
        return;
    }
    lastResume = now;

    window.dispatchEvent(new CustomEvent('app-resumed', { detail: { awayMs: away } }));

    // Reconnect in the background, after the refresh above is already on its way.
    const pusher = window.Echo?.connector?.pusher;
    if (pusher && (pusher.connection.state !== 'connected' || away > STALE_AFTER_MS)) {
        pusher.connection.state === 'connected' ? (pusher.disconnect(), pusher.connect()) : pusher.connect();
    }
}

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        hiddenAt = Date.now();
    } else {
        resume();
    }
});

// Back/forward cache restores and the network returning count as resuming too.
addEventListener('pageshow', (event) => event.persisted && resume());
addEventListener('online', resume);
