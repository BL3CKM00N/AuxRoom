@props(['account' => null])

<div class="text-sm text-aux-muted space-y-3">
    <p>
        AuxRoom doesn't ship with a shared Spotify app — connect <strong class="text-aux-text">your own</strong>
        Spotify Developer app so your credentials and playback stay under your control.
    </p>
    <ol class="list-decimal list-inside space-y-1 text-xs">
        <li>Create an app at <a class="underline" href="https://developer.spotify.com/dashboard" target="_blank" rel="noopener">developer.spotify.com/dashboard</a>.</li>
        <li>Add this exact Redirect URI to your app's settings:
            <code class="block mt-1 p-2 bg-aux-card-hover rounded text-[11px] break-all">{{ route('spotify.callback') }}</code>
        </li>
        <li>Copy the Client ID and Client Secret below.</li>
    </ol>
</div>

<form method="POST" action="{{ route('spotify.connect') }}" class="mt-4 space-y-3">
    @csrf

    <div>
        <x-input-label for="client_id" value="Client ID" />
        <x-text-input id="client_id" name="client_id" type="text" class="mt-1 block w-full text-sm"
            value="{{ old('client_id', $account->client_id ?? '') }}" required />
        <x-input-error :messages="$errors->get('client_id')" class="mt-1" />
    </div>

    <div>
        <x-input-label for="client_secret" value="Client Secret" />
        <x-text-input id="client_secret" name="client_secret" type="password" class="mt-1 block w-full text-sm" required />
        <x-input-error :messages="$errors->get('client_secret')" class="mt-1" />
    </div>

    <button type="submit" class="inline-flex items-center px-4 py-2 bg-aux-accent text-black rounded-full text-xs font-semibold uppercase tracking-widest hover:bg-aux-accent-strong">
        Connect Spotify
    </button>
</form>
