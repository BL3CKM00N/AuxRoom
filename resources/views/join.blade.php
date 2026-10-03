<x-guest-layout :preview="$preview">
    <div class="mb-4 text-sm text-aux-muted">
        Enter your name and the room's invite code to join.
    </div>

    @if (session('status'))
        <div class="mb-4 p-3 bg-aux-accent-soft text-aux-accent rounded text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 p-3 bg-red-500/10 text-red-400 rounded text-sm">
            {{ $errors->first() }}
            @if ($errors->has('invite_code'))
                <a href="{{ route('help') }}#join-code" target="_blank" class="ml-1 underline underline-offset-2 hover:text-red-300">What does this mean?</a>
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('join.store') }}" class="space-y-4">
        @csrf

        <div>
            <x-input-label for="name" value="Your name" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" required autofocus
                value="{{ old('name') }}" />
        </div>

        <div>
            <x-input-label for="invite_code" value="Invite code" />
            <x-text-input id="invite_code" name="invite_code" type="text" class="mt-1 block w-full"
                placeholder="XXXXXX-XXXXXX-XXXXXX" required value="{{ old('invite_code', $code) }}" />
            <div class="mt-2">
                <x-qr-scan-button target="invite_code" next="name" />
            </div>
        </div>

        <x-primary-button type="submit" class="w-full justify-center">Join room</x-primary-button>
    </form>

    <p class="mt-5 text-center text-xs text-aux-faint">
        Trouble joining? <a href="{{ route('help') }}" class="underline underline-offset-2 hover:text-aux-text">Help with errors</a>
    </p>
</x-guest-layout>
