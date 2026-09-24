{{-- Aurora background used behind full-page hero/auth/party content. The
     Alpine bindings are harmless on pages without Alpine loaded (they just
     never fire, and --px/--py default to 0 in app.css), so this can be
     dropped in anywhere without a hard dependency on Livewire being present.

     The ribbons morph in place via native SVG <animate> on each path's `d`
     (a 180s loop back to its starting shape); the sky and stars remain
     still. The wrapping <div class="aurora-parallax"> is our own addition on
     top of that for a bit of cursor depth (see app.css / ambient-background.js). --}}
<div class="green-waves" aria-hidden="true" x-data="ambientBackground()" @mousemove.window="onMouseMove($event)">
    <div class="aurora-parallax" style="--depth: 1">
        <div class="aurora-art">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 1000" preserveAspectRatio="xMidYMid slice" fill="none">
                <defs>
                    <linearGradient id="curtain" x1="650" y1="170" x2="860" y2="650" gradientUnits="userSpaceOnUse">
                        <stop stop-color="#32b780" stop-opacity="0"/>
                        <stop offset=".3" stop-color="#249f6c" stop-opacity=".02"/>
                        <stop offset=".65" stop-color="#38d990" stop-opacity=".13"/>
                        <stop offset=".86" stop-color="#59e899" stop-opacity=".32"/>
                        <stop offset="1" stop-color="#a2ffc1" stop-opacity=".55"/>
                    </linearGradient>
                    <linearGradient id="upper" x1="900" y1="-60" x2="1180" y2="400" gradientUnits="userSpaceOnUse">
                        <stop stop-color="#23a58a" stop-opacity="0"/>
                        <stop offset=".58" stop-color="#25ba83" stop-opacity=".04"/>
                        <stop offset=".87" stop-color="#4cdba1" stop-opacity=".2"/>
                        <stop offset="1" stop-color="#71efb0" stop-opacity=".35"/>
                    </linearGradient>
                    <linearGradient id="light" x1="80" y1="690" x2="1520" y2="300" gradientUnits="userSpaceOnUse">
                        <stop stop-color="#188559" stop-opacity="0"/>
                        <stop offset=".24" stop-color="#41d488" stop-opacity=".24"/>
                        <stop offset=".5" stop-color="#a6ffbd" stop-opacity=".85"/>
                        <stop offset=".68" stop-color="#78ffaf" stop-opacity=".72"/>
                        <stop offset=".87" stop-color="#2ebd87" stop-opacity=".35"/>
                        <stop offset="1" stop-color="#1b7d66" stop-opacity="0"/>
                    </linearGradient>
                    <radialGradient id="atmosphere">
                        <stop stop-color="#24bd70" stop-opacity=".18"/>
                        <stop offset=".5" stop-color="#0e7852" stop-opacity=".07"/>
                        <stop offset="1" stop-color="#0e7852" stop-opacity="0"/>
                    </radialGradient>
                    {{-- userSpaceOnUse with an explicit region (rather than the
                         default bounding-box-relative %) so the blur region
                         doesn't reflow, and clip, as each path's `d` morphs. --}}
                    <filter id="soft" filterUnits="userSpaceOnUse" x="-260" y="-300" width="2120" height="1400" color-interpolation-filters="sRGB"><feGaussianBlur stdDeviation="12"/></filter>
                    <filter id="bloom" filterUnits="userSpaceOnUse" x="-260" y="-300" width="2120" height="1400" color-interpolation-filters="sRGB"><feGaussianBlur stdDeviation="28"/></filter>
                    <filter id="edge" filterUnits="userSpaceOnUse" x="-260" y="-300" width="2120" height="1400" color-interpolation-filters="sRGB"><feGaussianBlur stdDeviation="2.5"/></filter>
                    <path id="fold" d="M-160 530 C100 470 220 715 505 685 C725 662 910 462 1050 395 C1280 285 1470 410 1750 190">
                        <animate attributeName="d" dur="180s" repeatCount="indefinite" calcMode="spline" keyTimes="0;.25;.5;.75;1" keySplines=".42 0 .58 1;.42 0 .58 1;.42 0 .58 1;.42 0 .58 1" values="M-160 530 C100 470 220 715 505 685 C725 662 910 462 1050 395 C1280 285 1470 410 1750 190;M-160 410 C100 300 220 380 505 490 C725 575 910 650 1050 530 C1280 333 1470 460 1750 600;M-160 310 C100 220 220 240 505 350 C725 435 910 650 1050 650 C1280 650 1470 430 1750 580;M-160 550 C100 650 220 530 505 390 C725 282 910 360 1050 430 C1280 545 1470 380 1750 300;M-160 530 C100 470 220 715 505 685 C725 662 910 462 1050 395 C1280 285 1470 410 1750 190"/>
                    </path>
                    <path id="distant-fold" d="M200 440 C485 500 720 409 888 250 C1060 85 1240 320 1720 95">
                        <animate attributeName="d" dur="180s" repeatCount="indefinite" calcMode="spline" keyTimes="0;.25;.5;.75;1" keySplines=".42 0 .58 1;.42 0 .58 1;.42 0 .58 1;.42 0 .58 1" values="M200 440 C485 500 720 409 888 250 C1060 85 1240 320 1720 95;M200 215 C485 140 720 220 888 330 C1060 440 1240 310 1720 370;M200 185 C485 155 720 340 888 375 C1060 410 1240 250 1720 430;M200 380 C485 390 720 160 888 160 C1060 160 1240 325 1720 240;M200 440 C485 500 720 409 888 250 C1060 85 1240 320 1720 95"/>
                    </path>
                </defs>
                <ellipse cx="910" cy="485" rx="720" ry="400" fill="url(#atmosphere)"/>
                {{-- A distant, subdued curtain gives the scene depth. --}}
                <g opacity=".55">
                    <path d="M180 60 C570 220 680 -80 930 -120 L1720 -120 L1720 95 C1240 320 1060 85 888 250 C720 409 485 500 200 440Z" fill="url(#upper)" filter="url(#soft)">
                        <animate attributeName="d" dur="180s" repeatCount="indefinite" calcMode="spline" keyTimes="0;.25;.5;.75;1" keySplines=".42 0 .58 1;.42 0 .58 1;.42 0 .58 1;.42 0 .58 1" values="M180 60 C570 220 680 -80 930 -120 L1720 -120 L1720 95 C1240 320 1060 85 888 250 C720 409 485 500 200 440Z;M180 60 C570 220 680 -80 930 -120 L1720 -120 L1720 370 C1240 310 1060 440 888 330 C720 220 485 140 200 215Z;M180 60 C570 220 680 -80 930 -120 L1720 -120 L1720 430 C1240 250 1060 410 888 375 C720 340 485 155 200 185Z;M180 60 C570 220 680 -80 930 -120 L1720 -120 L1720 240 C1240 325 1060 160 888 160 C720 160 485 390 200 380Z;M180 60 C570 220 680 -80 930 -120 L1720 -120 L1720 95 C1240 320 1060 85 888 250 C720 409 485 500 200 440Z"/>
                    </path>
                    <use href="#distant-fold" stroke="url(#light)" stroke-width="23" filter="url(#bloom)"/>
                    <use href="#distant-fold" stroke="url(#light)" stroke-width="3" opacity=".45" filter="url(#edge)"/>
                </g>
                {{-- Continuous translucent light, with no repeated stripe pattern. --}}
                <path d="M-160 140 C200 80 365 300 610 280 C880 255 1090 -120 1750 -170 L1750 190 C1470 410 1280 285 1050 395 C910 462 725 662 505 685 C220 715 100 470 -160 530Z" fill="url(#curtain)" filter="url(#soft)">
                    <animate attributeName="d" dur="180s" repeatCount="indefinite" calcMode="spline" keyTimes="0;.25;.5;.75;1" keySplines=".42 0 .58 1;.42 0 .58 1;.42 0 .58 1;.42 0 .58 1" values="M-160 140 C200 80 365 300 610 280 C880 255 1090 -120 1750 -170 L1750 190 C1470 410 1280 285 1050 395 C910 462 725 662 505 685 C220 715 100 470 -160 530Z;M-160 140 C200 80 365 300 610 280 C880 255 1090 -120 1750 -170 L1750 600 C1470 460 1280 333 1050 530 C910 650 725 575 505 490 C220 380 100 300 -160 410Z;M-160 140 C200 80 365 300 610 280 C880 255 1090 -120 1750 -170 L1750 580 C1470 430 1280 650 1050 650 C910 650 725 435 505 350 C220 240 100 220 -160 310Z;M-160 140 C200 80 365 300 610 280 C880 255 1090 -120 1750 -170 L1750 300 C1470 380 1280 545 1050 430 C910 360 725 282 505 390 C220 530 100 650 -160 550Z;M-160 140 C200 80 365 300 610 280 C880 255 1090 -120 1750 -170 L1750 190 C1470 410 1280 285 1050 395 C910 462 725 662 505 685 C220 715 100 470 -160 530Z"/>
                </path>
                <use href="#fold" stroke="url(#light)" stroke-width="65" opacity=".52" filter="url(#bloom)"/>
                <use href="#fold" stroke="url(#light)" stroke-width="17" opacity=".65" filter="url(#soft)"/>
                <use href="#fold" stroke="url(#light)" stroke-width="3" opacity=".62" filter="url(#edge)"/>
                <g fill="#cce9df" opacity=".3">
                    <circle cx="258" cy="230" r=".8"/><circle cx="439" cy="167" r=".7"/>
                    <circle cx="708" cy="208" r=".75"/><circle cx="997" cy="128" r=".65"/>
                    <circle cx="1285" cy="209" r=".8"/><circle cx="1438" cy="461" r=".65"/>
                    <circle cx="360" cy="492" r=".65"/><circle cx="1190" cy="641" r=".7"/>
                </g>
            </svg>
        </div>
    </div>
</div>
<div class="ambient-grain" aria-hidden="true"></div>

<script>
    // The morph above is native SVG SMIL, not CSS, so `prefers-reduced-motion`
    // doesn't touch it automatically, and it isn't paused for a hidden tab
    // either. Plain inline script (not the ambientBackground Alpine
    // component) so this still runs on pages without Alpine/app.js loaded,
    // like the error pages.
    (() => {
        const scene = document.querySelector('.aurora-art svg');
        if (!scene) {
            return;
        }

        const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');

        function syncMotion() {
            if (reducedMotion.matches) {
                scene.setCurrentTime(0);
                scene.pauseAnimations();
            } else if (document.hidden) {
                scene.pauseAnimations();
            } else {
                scene.unpauseAnimations();
            }
        }

        reducedMotion.addEventListener('change', syncMotion);
        document.addEventListener('visibilitychange', syncMotion);
        syncMotion();
    })();
</script>
