{{-- ?debug=1 on a room address: how accurate this screen is. See resources/js/alpine/debug-hud.js. --}}
<div x-data="debugHud()" x-cloak
     class="fixed bottom-2 left-2 z-50 max-w-xs rounded-lg bg-black/80 border border-white/10 p-3 font-mono text-[10px] leading-4 text-white/80 pointer-events-none">
    <p class="font-semibold text-white">Accuracy</p>
    <p>clock: offset <span x-text="clock.offset"></span> ms, round trip <span x-text="clock.rtt ?? 'n/a'"></span> ms <span x-text="clock.synced ? '' : '(not measured yet)'"></span></p>
    <p>live connection: <span x-text="socket"></span>, <span x-text="pushes"></span> pushes, <span x-text="gaps"></span> gaps</p>
    <p>page reached this device <span x-text="renderArrivalMs ?? 'n/a'"></span> ms after the server drew it</p>
    <p>push to screen: <span x-text="pushToRenderMs ?? 'n/a'"></span> ms</p>
    <p>server confirmed Spotify <span x-text="verifiedAge ?? 'n/a'"></span> s before drawing</p>
    <p>progress: shown <span x-text="shownPositionMs ?? 'n/a'"></span> ms, off from the server by <span x-text="drift ?? 'n/a'"></span> ms</p>
</div>
