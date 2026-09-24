document.addEventListener('alpine:init', () => {
    // Eases the ambient blobs toward the cursor instead of tracking it
    // directly (a lerp toward a target each frame, not the raw mouse
    // position) so the motion reads as floaty rather than jittery, and
    // stays perfectly still whenever the pointer isn't moving.
    Alpine.data('ambientBackground', () => ({
        enabled: false,
        targetX: 0,
        targetY: 0,
        currentX: 0,
        currentY: 0,
        raf: null,

        init() {
            this.enabled = window.matchMedia('(pointer: fine)').matches
                && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            if (this.enabled) {
                this.raf = requestAnimationFrame(() => this.tick());
            }
        },

        destroy() {
            if (this.raf) {
                cancelAnimationFrame(this.raf);
            }
        },

        onMouseMove(event) {
            if (!this.enabled) {
                return;
            }

            this.targetX = (event.clientX / window.innerWidth) * 2 - 1;
            this.targetY = (event.clientY / window.innerHeight) * 2 - 1;
        },

        tick() {
            this.currentX += (this.targetX - this.currentX) * 0.04;
            this.currentY += (this.targetY - this.currentY) * 0.04;

            this.$el.style.setProperty('--px', this.currentX.toFixed(4));
            this.$el.style.setProperty('--py', this.currentY.toFixed(4));

            this.raf = requestAnimationFrame(() => this.tick());
        },
    }));
});
