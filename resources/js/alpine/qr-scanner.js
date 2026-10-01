// Matches an AuxRoom invite code anywhere in a scanned string, so it works
// whether the QR holds the full join URL (what the app generates), a
// /rooms/{code} link, or just the bare code. Case-insensitive, and loose on
// the alphabet so codes issued before the unambiguous alphabet still scan.
const INVITE_CODE = /[A-Z0-9]{6}-[A-Z0-9]{6}-[A-Z0-9]{6}/i;

export function extractInviteCode(text) {
    const match = String(text ?? '').match(INVITE_CODE);

    return match ? match[0].toUpperCase() : null;
}

document.addEventListener('alpine:init', () => {
    // Why this exists at all: an installed PWA can't hand a QR to the system
    // camera app and get the link back into itself (the link opens in the
    // browser instead, with separate storage), so people had to type the
    // code by hand. This scans inside the app and fills the field.
    Alpine.data('qrScanner', (targetId, nextFocusId = null) => ({
        open: false,
        // idle | starting | scanning | denied | unavailable | nopicture | error
        status: 'idle',
        notice: '',
        supported: !!navigator.mediaDevices?.getUserMedia,

        stream: null,
        frame: null,
        lastScan: 0,
        decode: null,

        async start() {
            this.open = true;
            this.status = 'starting';
            this.notice = '';

            try {
                // Loaded on first use only, so the decoder isn't part of the
                // bundle every page pays for.
                this.decode ??= (await import('jsqr')).default;

                this.stream = await this.openCamera();

                const video = this.$refs.video;

                // All of this goes on before the stream is attached: iOS
                // decides inline playback and autoplay eligibility at attach
                // time, and gets it wrong (black frame) if it's set after.
                video.muted = true;
                video.autoplay = true;
                video.setAttribute('muted', '');
                video.setAttribute('playsinline', '');
                video.setAttribute('webkit-playsinline', '');
                video.srcObject = this.stream;

                await this.waitForPicture(video);

                this.status = 'scanning';
                this.frame = requestAnimationFrame(() => this.tick());
            } catch (error) {
                console.error('QR scanner could not start the camera', error);
                this.stop();
                this.status = this.statusFor(error);
            }
        },

        // Tries progressively simpler requests: some devices reject the
        // resolution hints or have no camera that matches facingMode, and
        // that shouldn't read as "no camera". A permission denial is final
        // and is never retried.
        async openCamera() {
            const attempts = [
                { video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false },
                { video: { facingMode: 'environment' }, audio: false },
                { video: true, audio: false },
            ];
            let lastError;

            for (const constraints of attempts) {
                try {
                    return await navigator.mediaDevices.getUserMedia(constraints);
                } catch (error) {
                    lastError = error;

                    if (!['OverconstrainedError', 'NotFoundError', 'TypeError'].includes(error?.name)) {
                        throw error;
                    }
                }
            }

            throw lastError;
        },

        // A camera that's "on" but never delivers a frame is the black-screen
        // failure: without this it just sits there forever. Resolves once
        // real frames arrive, rejects after 5s so the person gets a message
        // and a way out instead.
        waitForPicture(video) {
            return new Promise((resolve, reject) => {
                const startedAt = performance.now();

                video.play().catch((error) => {
                    console.error('Camera video could not start playing', error);
                    reject(Object.assign(new Error('play() was rejected'), { name: 'NoPictureError' }));
                });

                const check = () => {
                    if (video.videoWidth > 0 && video.readyState >= 2) {
                        resolve();

                        return;
                    }

                    if (performance.now() - startedAt > 5000) {
                        reject(Object.assign(new Error('The camera opened but produced no picture'), { name: 'NoPictureError' }));

                        return;
                    }

                    setTimeout(check, 100);
                };

                check();
            });
        },

        statusFor(error) {
            switch (error?.name) {
                case 'NotAllowedError':
                case 'SecurityError':
                    return 'denied';
                case 'NotFoundError':
                case 'OverconstrainedError':
                case 'NotReadableError':
                    return 'unavailable';
                case 'NoPictureError':
                    return 'nopicture';
                default:
                    return 'error';
            }
        },

        tick() {
            if (this.status !== 'scanning') {
                return;
            }

            const video = this.$refs.video;
            const now = performance.now();

            // ~8 scans a second is plenty for a QR code held still, and keeps
            // a phone from heating up decoding every single frame.
            if (video.readyState >= 2 && video.videoWidth && now - this.lastScan > 120) {
                this.lastScan = now;
                this.scanFrame(video);
            }

            if (this.status === 'scanning') {
                this.frame = requestAnimationFrame(() => this.tick());
            }
        },

        scanFrame(video) {
            const canvas = this.$refs.canvas;
            const scale = Math.min(1, 640 / Math.max(video.videoWidth, video.videoHeight));
            canvas.width = Math.round(video.videoWidth * scale);
            canvas.height = Math.round(video.videoHeight * scale);

            const context = canvas.getContext('2d', { willReadFrequently: true });
            context.drawImage(video, 0, 0, canvas.width, canvas.height);

            const image = context.getImageData(0, 0, canvas.width, canvas.height);
            const result = this.decode(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' });

            if (!result) {
                return;
            }

            const code = extractInviteCode(result.data);

            if (code) {
                this.fill(code);

                return;
            }

            this.notice = "That QR code isn't an AuxRoom invite.";
        },

        fill(code) {
            const input = document.getElementById(targetId);

            if (input) {
                input.value = code;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }

            this.close();

            // Whatever's still missing is next: usually the name.
            const next = nextFocusId && document.getElementById(nextFocusId);

            if (next && !next.value) {
                next.focus();
            }
        },

        stop() {
            cancelAnimationFrame(this.frame);
            this.frame = null;

            // The camera light stays on until every track is stopped.
            this.stream?.getTracks().forEach((track) => track.stop());
            this.stream = null;

            if (this.$refs.video) {
                this.$refs.video.srcObject = null;
            }
        },

        close() {
            this.stop();
            this.open = false;
            this.status = 'idle';
            this.notice = '';
        },
    }));
});
