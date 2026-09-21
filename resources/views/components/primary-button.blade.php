<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-aux-accent border border-transparent rounded-full font-semibold text-xs text-black uppercase tracking-widest hover:bg-aux-accent-strong focus:bg-aux-accent-strong active:bg-aux-accent-strong focus:outline-none focus:ring-2 focus:ring-aux-accent focus:ring-offset-2 focus:ring-offset-aux-bg transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
