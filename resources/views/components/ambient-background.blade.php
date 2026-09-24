{{-- Aurora background used behind full-page hero/auth/party content. The
     Alpine bindings are harmless on pages without Alpine loaded (they just
     never fire, and --px/--py default to 0 in app.css), so this can be
     dropped in anywhere without a hard dependency on Livewire being present. --}}
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
                    <filter id="soft" x="-25%" y="-60%" width="150%" height="220%" color-interpolation-filters="sRGB"><feGaussianBlur stdDeviation="12"/></filter>
                    <filter id="bloom" x="-25%" y="-60%" width="150%" height="220%" color-interpolation-filters="sRGB"><feGaussianBlur stdDeviation="28"/></filter>
                    <filter id="edge" x="-15%" y="-25%" width="130%" height="150%" color-interpolation-filters="sRGB"><feGaussianBlur stdDeviation="2.5"/></filter>
                    <path id="fold" d="M-160 530 C100 470 220 715 505 685 C725 662 910 462 1050 395 C1280 285 1470 410 1750 190"/>
                    <path id="distant-fold" d="M200 440 C485 500 720 409 888 250 C1060 85 1240 320 1720 95"/>
                </defs>
                <ellipse cx="910" cy="485" rx="720" ry="400" fill="url(#atmosphere)"/>
                {{-- A distant, subdued curtain gives the scene depth. --}}
                <g opacity=".55">
                    <path d="M180 60 C570 220 680 -80 930 -120 L1720 -120 L1720 95 C1240 320 1060 85 888 250 C720 409 485 500 200 440Z" fill="url(#upper)" filter="url(#soft)"/>
                    <use href="#distant-fold" stroke="url(#light)" stroke-width="23" filter="url(#bloom)"/>
                    <use href="#distant-fold" stroke="url(#light)" stroke-width="3" opacity=".45" filter="url(#edge)"/>
                </g>
                {{-- Continuous translucent light, with no repeated stripe pattern. --}}
                <path d="M-160 140 C200 80 365 300 610 280 C880 255 1090 -120 1750 -170 L1750 190 C1470 410 1280 285 1050 395 C910 462 725 662 505 685 C220 715 100 470 -160 530Z" fill="url(#curtain)" filter="url(#soft)"/>
                <use href="#fold" stroke="url(#light)" stroke-width="65" opacity=".52" filter="url(#bloom)"/>
                <use href="#fold" stroke="url(#light)" stroke-width="17" opacity=".65" filter="url(#soft)"/>
                <use href="#fold" stroke="url(#light)" stroke-width="3" opacity=".62" filter="url(#edge)"/>
                {{-- A broad overlapping fold, softly dissolved into the main curtain. --}}
                <path d="M320 270 C480 315 685 367 867 266 C750 453 662 605 505 671 C655 507 555 385 320 270Z" fill="url(#curtain)" opacity=".42" filter="url(#soft)"/>
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
