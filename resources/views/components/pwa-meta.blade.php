{{-- Home-screen install support. iOS Safari mostly ignores the web manifest
     for "Add to Home Screen" and relies on these apple-* tags instead; the
     manifest link is kept alongside for other browsers. --}}
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="theme-color" content="#0a0d12">

<link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="AuxRoom">
