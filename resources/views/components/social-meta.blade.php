@props([
    'title' => null,
    'description' => null,
    'url' => null,
    'noindex' => false,
    'image' => null,
    'imageSquare' => null,
])
@php
    // Callers (e.g. GuestLayout) may pass an explicit null through to opt
    // into the default, rather than omitting the attribute entirely, which
    // is the only case @props' own default array covers.
    $title ??= 'AuxRoom';
    $description ??= 'One shared soundtrack for the room. Everyone gets a turn on the queue, no Spotify account needed to join.';
    // The address the card stands for. Facebook, Messenger, LinkedIn and
    // iMessage treat og:url as the real link, so for an invite it must keep
    // the ?code=..., and url()->current() would strip the query string.
    $url ??= url()->current();
    // A page may bring its own pictures (an invite card for one room);
    // everything else uses the shared ones.
    $image ??= asset('images/og-image.jpg');
    $imageSquare ??= asset('images/og-image-square.jpg');
@endphp
<meta name="description" content="{{ $description }}">
<link rel="canonical" href="{{ $url }}">
@if ($noindex)
    {{-- Invite and party links are for the people they were sent to, not for search results. Chat previews ignore this. --}}
    <meta name="robots" content="noindex">
@endif
{{-- Open Graph / Twitter Card tags, so links to this page get a real
     preview (title, description, image) when posted in WhatsApp, Discord,
     Telegram, Snapchat, iMessage, etc. instead of a bare URL.

     Two og:image entries, not one: the Open Graph spec allows repeating the
     property, and crawlers pick whichever fits their layout. WhatsApp,
     Discord and Telegram all use the 1200x630 landscape one; Snapchat's
     link-preview crawler specifically wants a square "site icon"-style
     image instead and otherwise renders poorly, so it gets its own. --}}
<meta property="og:type" content="website">
<meta property="og:site_name" content="AuxRoom">
<meta property="og:locale" content="en_US">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">

<meta property="og:image" content="{{ $image }}">
<meta property="og:image:secure_url" content="{{ $image }}">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ $title }}">

<meta property="og:image" content="{{ $imageSquare }}">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="1200">
<meta property="og:image:alt" content="{{ $title }}">

<meta property="og:url" content="{{ $url }}">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
<meta name="twitter:image" content="{{ $image }}">
<meta name="twitter:image:alt" content="{{ $title }}">
