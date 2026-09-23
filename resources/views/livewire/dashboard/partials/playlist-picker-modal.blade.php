{{-- Playlist picker — search or browse, then pick one to play immediately --}}
@if ($showPlaylistPicker)
    <div class="fixed inset-0 z-40 flex items-center justify-center px-4">
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" wire:click="closePlaylistPicker"></div>
        <div class="relative w-full max-w-lg max-h-[80vh] flex flex-col bg-aux-card border border-aux-border rounded-xl p-6">
            <div class="flex items-center justify-between shrink-0">
                <h3 class="font-semibold">Choose a playlist</h3>
                <button wire:click="closePlaylistPicker" class="text-aux-muted hover:text-aux-text">
                    <x-icon name="x-mark" class="w-5 h-5" />
                </button>
            </div>

            <div class="mt-4 relative shrink-0">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-aux-faint">
                    <x-icon name="search" class="w-4 h-4" />
                </span>
                <input type="text" wire:model.live.debounce.400ms="playlistQuery" autofocus
                       placeholder="Paste a playlist link or search…"
                       class="w-full pl-9 pr-3 py-2 rounded-full bg-aux-card-hover border border-aux-border text-sm placeholder:text-aux-faint focus:outline-none focus:ring-1 focus:ring-aux-accent">
            </div>

            @if (trim($playlistQuery) !== '')
                <button type="button" wire:click="browseMyPlaylists" class="mt-2 self-start text-xs font-medium text-aux-accent shrink-0">
                    Clear and show my playlists
                </button>
            @endif

            <ul class="mt-4 space-y-1 overflow-y-auto">
                @forelse ($playlistResults as $i => $p)
                    <li class="flex items-center gap-3 p-2 rounded-lg hover:bg-white/5">
                        <div class="w-11 h-11 rounded-md bg-aux-card-hover flex items-center justify-center shrink-0 overflow-hidden">
                            @if ($p['image_url'])
                                <img src="{{ $p['image_url'] }}" class="w-full h-full object-cover" alt="">
                            @else
                                <x-icon name="queue-list" class="w-4 h-4 text-aux-faint" />
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ $p['name'] }}</p>
                            <p class="truncate text-xs text-aux-faint">{{ $p['owner'] }}{{ $p['track_count'] !== null ? ' · '.$p['track_count'].' tracks' : '' }}</p>
                        </div>
                        <button wire:click="playPlaylist({{ $i }})"
                                class="shrink-0 px-3 py-1.5 rounded-full bg-aux-accent text-black text-xs font-semibold hover:bg-aux-accent-strong">
                            Play
                        </button>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-aux-faint">
                        {{ trim($playlistQuery) !== '' ? 'No playlists found.' : "You don't have any playlists yet." }}
                    </li>
                @endforelse
            </ul>
        </div>
    </div>
@endif
