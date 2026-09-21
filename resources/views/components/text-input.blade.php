@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-aux-border bg-aux-card-hover text-aux-text focus:border-aux-accent focus:ring-aux-accent rounded-md shadow-sm']) }}>
