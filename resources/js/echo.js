import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// Live updates over Reverb. Everything is worked out at runtime from the page
// itself, not from build-time variables (production never filled those in):
// the key comes from a meta tag (see <x-realtime-meta />), the host is the page's
// own host, and TLS follows the page's protocol. In production the proxy sends
// /app on the site's own address to the Reverb server; locally Reverb is on
// port 8080.
const key = document.querySelector('meta[name="reverb-key"]')?.content;

if (key) {
    const local = ['localhost', '127.0.0.1', '[::1]'].includes(location.hostname);

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: location.hostname,
        wsPort: local ? 8080 : 80,
        wssPort: local ? 8080 : 443,
        forceTLS: location.protocol === 'https:',
        enabledTransports: ['ws', 'wss'],
    });
}
