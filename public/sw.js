// Deliberately minimal: this app needs a live connection (WebSockets,
// Spotify's API) to do anything useful, so there's no attempt at real
// offline functionality or asset caching here. The only job is to swap
// Safari/Chrome's native "no internet" page for a branded one on navigation
// requests, which matters most for the iOS home-screen install (no browser
// chrome to show a URL bar/reload button of its own).
const OFFLINE_URL = '/offline.html';
const CACHE_NAME = 'auxroom-offline-v1';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.add(OFFLINE_URL))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
        ))
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') {
        return;
    }

    event.respondWith(
        fetch(event.request).catch(() => caches.match(OFFLINE_URL))
    );
});
