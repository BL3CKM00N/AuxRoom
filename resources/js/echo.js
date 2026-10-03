import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// Live updates over Reverb. The VITE_REVERB_* variables in .env are the
// settings, as before. But production passed them to the build as the literal
// text "${REVERB_HOST}" (nothing expanded it), so the browser tried to connect
// to a host with that name and live updates never worked. A value that is empty
// or still contains "${" is therefore treated as unset and replaced by what the
// page itself knows: the key from a meta tag (see <x-realtime-meta />), the
// page's own host, and TLS by the page's protocol. In production the proxy sends
// /app on the site's own address to the Reverb server; locally Reverb is on 8080.
const fromEnv = (value) => (typeof value === 'string' && value !== '' && !value.includes('${') ? value : undefined);

const key = fromEnv(import.meta.env.VITE_REVERB_APP_KEY) ?? document.querySelector('meta[name="reverb-key"]')?.content;

if (key) {
    const local = ['localhost', '127.0.0.1', '[::1]'].includes(location.hostname);
    const scheme = fromEnv(import.meta.env.VITE_REVERB_SCHEME) ?? (location.protocol === 'https:' ? 'https' : 'http');
    const port = Number(fromEnv(import.meta.env.VITE_REVERB_PORT) ?? (local ? 8080 : scheme === 'https' ? 443 : 80));

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: fromEnv(import.meta.env.VITE_REVERB_HOST) ?? location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
