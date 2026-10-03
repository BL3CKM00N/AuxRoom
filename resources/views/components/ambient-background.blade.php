{{-- Aurora background used behind full-page hero/auth/party content.

     Two pre-rendered pictures of the aurora (public/images/aurora-a.jpg and
     aurora-b.jpg, captured from the original morphing SVG at two moments of
     its loop) are drawn onto two small canvases, and CSS cross-fades one over
     the other very slowly. That fade runs on the GPU compositor, so after the
     first paint the page does no per-frame work for it. The previous version
     morphed six blurred SVG layers every frame, which cost several hundred MB
     and a full CPU core or more, enough to make the pages slow.

     Canvases rather than <img>: the browser keeps a canvas as a texture of its
     own size (about 5 MB each) and the GPU scales it, whereas an <img> in a
     composited layer is rastered at full screen resolution (45 MB at 4K).

     wire:ignore: the script below adds classes to these elements, and
     Livewire would otherwise reset them every time the page re-renders. A
     plain inline script, not Alpine, so this also works on pages without
     Alpine loaded, like the error pages. --}}
<div class="green-waves" aria-hidden="true" wire:ignore>
    <div class="aurora-parallax">
        <canvas class="aurora-layer" width="1392" height="870" data-src="{{ asset('images/aurora-a.jpg') }}"></canvas>
        <canvas class="aurora-layer aurora-layer-b" width="1392" height="870" data-src="{{ asset('images/aurora-b.jpg') }}"></canvas>
    </div>
</div>
{{-- The image URL is set here, not in the CSS, so it always points at this app (Vite's dev server would rewrite it to its own port). --}}
<div class="ambient-grain" aria-hidden="true" style="background-image: url('{{ asset('images/grain.png') }}')"></div>

<script>
    (() => {
        const scene = document.querySelector('.green-waves');
        const parallax = scene && scene.querySelector('.aurora-parallax');
        if (!parallax) {
            return;
        }

        // Draw each picture once; the canvas keeps it, the decoded image is released.
        const canvases = [...scene.querySelectorAll('canvas[data-src]')];
        let loaded = 0;

        canvases.forEach((canvas) => {
            const image = new Image();
            image.decoding = 'async';
            image.onload = () => {
                canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
                if (++loaded === canvases.length) {
                    parallax.classList.add('is-ready');
                }
            };
            image.src = canvas.dataset.src;
        });

        // Cursor parallax: eases toward the pointer, and the frame loop runs
        // only while it is still moving. When the pointer rests, nothing runs.
        const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
        if (!matchMedia('(pointer: fine)').matches || reducedMotion.matches) {
            return;
        }

        let targetX = 0, targetY = 0, currentX = 0, currentY = 0, frame = null;

        const tick = () => {
            currentX += (targetX - currentX) * 0.06;
            currentY += (targetY - currentY) * 0.06;
            parallax.style.transform = `translate3d(${currentX.toFixed(3)}%, ${currentY.toFixed(3)}%, 0)`;

            frame = Math.abs(targetX - currentX) + Math.abs(targetY - currentY) > 0.002
                ? requestAnimationFrame(tick)
                : null;
        };

        addEventListener('mousemove', (event) => {
            targetX = (event.clientX / innerWidth) * 2 - 1;
            targetY = (event.clientY / innerHeight) * 2 - 1;
            frame ??= requestAnimationFrame(tick);
        }, { passive: true });
    })();
</script>
