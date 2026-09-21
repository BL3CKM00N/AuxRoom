<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-aux-card border border-aux-border rounded-full font-semibold text-xs text-aux-text uppercase tracking-widest shadow-sm hover:bg-aux-card-hover focus:outline-none focus:ring-2 focus:ring-aux-accent focus:ring-offset-2 focus:ring-offset-aux-bg disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
