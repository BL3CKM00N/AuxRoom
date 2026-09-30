@props([
    'title' => null,
    'description' => null,
])
@php
    // Callers (e.g. GuestLayout) may pass an explicit null through to opt
    // into the default, rather than omitting the attribute entirely, which
    // is the only case @props' own default array covers.
    $title ??= 'AuxRoom';
    $description ??= 'One shared soundtrack for the room. Everyone gets a turn on the queue, no Spotify account needed to join.';
@endphp
{{-- Open Graph / Twitter Card tags, so links to this page get a real
     preview (title, description, image) when posted in WhatsApp, Discord,
     iMessage, etc. instead of a bare URL. --}}
<meta property="og:type" content="website">
<meta property="og:site_name" content="AuxRoom">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:image" content="{{ asset('images/og-image.png') }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:url" content="{{ url()->current() }}">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
<meta name="twitter:image" content="{{ asset('images/og-image.png') }}">
