// Matches an AuxRoom invite code anywhere in a scanned string, so it works
// whether the QR holds the full join URL (what the app generates), a
// /rooms/{code} link, or just the bare code. Case-insensitive, and loose on
// the alphabet so codes issued before the unambiguous alphabet still scan.
const INVITE_CODE = /[A-Z0-9]{6}-[A-Z0-9]{6}-[A-Z0-9]{6}/i;

export function extractInviteCode(text) {
    const match = String(text ?? '').match(INVITE_CODE);

    return match ? match[0].toUpperCase() : null;
}

// Long side, in pixels, of the frame handed to the decoder. Big enough that a
// QR on a monitor across the room is still resolvable, small enough to
// decode several times a second on a phone.
const DECODE_MAX_SIDE = 960;

// A frame this dark on average, for this long, means the camera is "on" but
// delivering black (covered lens, or a broken feed), not a dim room.
const BLACK_MEAN_LUMA = 6;
const BLACK_FOR_MS = 4000;

document.addEventListener('alpine:init', () => {
    // Why this exists at all: an installed PWA can't hand a QR to the system
    // camera app and get the link back into itself (the link opens in the
    // browser instead, with separate storage), so people had to type the
    // code by hand. This scans inside the app and fills the field.
    //
    // The visible preview is a <canvas> this code paints each frame, not the
    // <video> element. On real iPhones a live camera <video> can report
    // frames yet paint solid black (hardware video layers are fragile
    // inside anything clipped, blended or animated), while drawing the very
    // same frames to a canvas works. The video stays in the DOM, playing,
    // purely as the frame source.
    Alpine.data('qrScanner', (targetId, nextFocusId = null) => ({
        open: false,
        // idle | starting | scanning | denied | unavailable | nopicture | error
        status: 'idle',
        notice: '',
        supported: !!navigator.mediaDevices?.getUserMedia,

        // ?scanDebug=1 on the page shows live diagnostics inside the scanner,
        // for working out what a specific phone is doing without a Mac.
        debug: new URLSearchParams(location.search).has('scanDebug'),
        debugInfo: '',

        stream: null,
        frame: null,
        lastDecode: 0,
        darkSince: null,
        decodedFrames: 0,
        decode: null,

        async start() {
            this.open = true;
            this.status = 'starting';
            this.notice = '';
            this.darkSince = null;
            this.decodedFrames = 0;

            // The animated, blurred aurora is expensive; with the camera
            // running as well it can starve a phone's compositor.
            document.documentElement.classList.add('scanner-open');

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

        // A camera that's "on" but never delivers a frame is a failure mode
        // of its own: without this it just sits there forever. Resolves once
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

            if (video.readyState >= 2 && video.videoWidth) {
                this.paintPreview(video);

                const now = performance.now();

                // ~6 decodes a second is plenty for a QR held still, and keeps
                // a phone from heating up. The preview itself runs every frame.
                if (now - this.lastDecode > 160) {
                    this.lastDecode = now;
                    this.scanFrame(video);
                }
            }

            if (this.status === 'scanning') {
                this.frame = requestAnimationFrame(() => this.tick());
            }
        },

        // Draws the camera frame, cropped to fill the preview the way
        // object-fit: cover would, onto the visible canvas.
        paintPreview(video) {
            const canvas = this.$refs.preview;
            const box = canvas.getBoundingClientRect();
            const dpr = Math.min(window.devicePixelRatio || 1, 2);
            let width = Math.round(box.width * dpr);
            let height = Math.round(box.height * dpr);

            if (!width || !height) {
                return;
            }

            const longest = Math.max(width, height);

            if (longest > DECODE_MAX_SIDE) {
                width = Math.round(width * (DECODE_MAX_SIDE / longest));
                height = Math.round(height * (DECODE_MAX_SIDE / longest));
            }

            if (canvas.width !== width || canvas.height !== height) {
                canvas.width = width;
                canvas.height = height;
            }

            const scale = Math.max(width / video.videoWidth, height / video.videoHeight);
            const sourceWidth = width / scale;
            const sourceHeight = height / scale;

            canvas.getContext('2d').drawImage(
                video,
                (video.videoWidth - sourceWidth) / 2,
                (video.videoHeight - sourceHeight) / 2,
                sourceWidth,
                sourceHeight,
                0,
                0,
                width,
                height,
            );
        },

        scanFrame(video) {
            const canvas = this.$refs.canvas;
            const scale = Math.min(1, DECODE_MAX_SIDE / Math.max(video.videoWidth, video.videoHeight));
            canvas.width = Math.round(video.videoWidth * scale);
            canvas.height = Math.round(video.videoHeight * scale);

            const context = canvas.getContext('2d', { willReadFrequently: true });
            context.drawImage(video, 0, 0, canvas.width, canvas.height);

            const image = context.getImageData(0, 0, canvas.width, canvas.height);
            this.decodedFrames++;

            const brightness = this.meanBrightness(image.data);

            if (this.updateDarkness(brightness)) {
                return;
            }

            if (this.debug) {
                this.debugInfo = this.diagnostics(video, canvas, brightness);
            }

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

        // Cheap: samples a sparse grid of pixels rather than all of them.
        meanBrightness(data) {
            let total = 0;
            let samples = 0;

            for (let i = 0; i < data.length; i += 4 * 97) {
                total += data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114;
                samples++;
            }

            return samples ? total / samples : 0;
        },

        // True once the feed has been black long enough to give up on it.
        updateDarkness(brightness) {
            if (brightness >= BLACK_MEAN_LUMA) {
                this.darkSince = null;

                return false;
            }

            this.darkSince ??= performance.now();

            if (performance.now() - this.darkSince < BLACK_FOR_MS) {
                return false;
            }

            console.error('QR scanner: the camera is delivering black frames');
            this.stop();
            this.status = 'nopicture';

            return true;
        },

        diagnostics(video, canvas, brightness) {
            const track = this.stream?.getVideoTracks()[0];
            const settings = track?.getSettings?.() ?? {};

            return [
                `video ${video.videoWidth}x${video.videoHeight} ready=${video.readyState} paused=${video.paused} t=${video.currentTime.toFixed(1)}`,
                `track ${settings.width ?? '?'}x${settings.height ?? '?'} ${settings.facingMode ?? '?'} state=${track?.readyState} muted=${track?.muted}`,
                `decode ${canvas.width}x${canvas.height} frames=${this.decodedFrames} brightness=${brightness.toFixed(1)}`,
                navigator.userAgent,
            ].join('\n');
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

            document.documentElement.classList.remove('scanner-open');
        },

        close() {
            this.stop();
            this.open = false;
            this.status = 'idle';
            this.notice = '';
        },
    }));
});
