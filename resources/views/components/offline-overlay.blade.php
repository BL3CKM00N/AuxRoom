{{-- Catches the case sw.js can't: losing connectivity while already sitting
     on a loaded page. A service worker only ever gets a chance to act on a
     fresh navigation request, so if nothing re-navigates, the page just sits
     there quietly failing its background requests. This listens for the
     browser's own online/offline events instead, so it shows up (and clears
     itself) without needing a reload either way. --}}
<div x-data="offlineOverlay()" x-show="offline" x-cloak
     class="fixed inset-0 z-50 flex flex-col items-center justify-center px-6 text-center bg-aux-bg/95 backdrop-blur-sm">
    <svg class="w-14 h-14 text-aux-accent" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 20h.01" />
        <path d="M2 8.82a15 15 0 0 1 20 0" />
        <path d="M5 12.859a10 10 0 0 1 14 0" />
        <path d="M8.5 16.429a5 5 0 0 1 7 0" />
        <line x1="3" y1="3" x2="21" y2="21" />
    </svg>
    <h2 class="mt-6 text-xl font-bold">You're offline</h2>
    <p class="mt-2 text-sm text-aux-muted max-w-xs">AuxRoom needs an internet connection to sync your room. We'll bring you back automatically once you're reconnected.</p>
</div>
