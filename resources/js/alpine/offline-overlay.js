document.addEventListener('alpine:init', () => {
    Alpine.data('offlineOverlay', () => ({
        offline: !navigator.onLine,

        init() {
            window.addEventListener('online', () => { this.offline = false; });
            window.addEventListener('offline', () => { this.offline = true; });
        },
    }));
});
