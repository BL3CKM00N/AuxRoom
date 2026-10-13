/**
 * The server's clock, as seen from this device. Every screen counts a song's progress from the moment the
 * server says it started, so every screen has to know what time the server thinks it is. This device's own
 * clock can be seconds off, so the difference is measured: a few tiny requests to /time, keeping the answer of
 * the quickest one (its delay is the smallest, so it is the most exact), assuming the reply took as long to
 * come back as the request took to go. Repeated every few minutes and whenever the app comes back to the front.
 */
const state = { offset: 0, rtt: null, synced: false, at: 0 };

// Until the first measurement, the time the page was drawn at is a fair guess (off only by the page's travel time).
const seed = Number(document.querySelector('meta[name="server-time"]')?.content);

if (seed) {
    state.offset = seed - Date.now();
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function sample() {
    const sent = Date.now();
    const response = await fetch('/time', { cache: 'no-store', credentials: 'omit' });
    const received = Date.now();
    const { now } = await response.json();

    return { rtt: received - sent, offset: now + (received - sent) / 2 - received };
}

let running = false;

async function sync(samples = 5) {
    if (running) {
        return;
    }

    running = true;
    let best = null;

    for (let i = 0; i < samples; i++) {
        try {
            const taken = await sample();
            best = best === null || taken.rtt < best.rtt ? taken : best;
        } catch {
            // Offline or the server is busy: the last measurement stands.
        }

        await sleep(120);
    }

    if (best) {
        Object.assign(state, { offset: best.offset, rtt: best.rtt, synced: true, at: Date.now() });
    }

    running = false;
}

window.serverClock = {
    now: () => Date.now() + state.offset,
    sync,
    get offset() { return state.offset; },
    get rtt() { return state.rtt; },
    get synced() { return state.synced; },
};

sync();
setInterval(sync, 5 * 60 * 1000);
window.addEventListener('app-resumed', () => sync(3));
window.addEventListener('online', () => sync(3));
