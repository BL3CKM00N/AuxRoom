@props(['class' => 'h-6'])
<img src="{{ asset('images/auxroom-logo.svg') }}" alt="AuxRoom" {{ $attributes->merge(['class' => $class.' w-auto']) }}>
