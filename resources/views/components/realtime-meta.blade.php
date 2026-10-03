{{-- The public Reverb app key, read by resources/js/echo.js at runtime. It
     used to be baked into the JS at build time through VITE_REVERB_* variables,
     which production never expanded, so the browser tried to connect to a host
     literally named "${REVERB_HOST}" and live updates never worked. Reading it
     from here needs no build-time variables at all. --}}
@if ($key = config('reverb.apps.apps.0.key'))
    <meta name="reverb-key" content="{{ $key }}">
@endif
