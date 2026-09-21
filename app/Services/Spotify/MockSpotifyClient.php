<?php

namespace App\Services\Spotify;

/**
 * Stand-in used when a room has no connected Spotify account yet, so the
 * room flow (queue, transport controls, guest permissions) can be exercised
 * without real credentials. Search and playlists are intentionally left
 * empty here rather than backed by a fake catalog — there's nothing real to
 * search until a room connects its own Spotify account, and pretending
 * otherwise with made-up results would be misleading.
 */
class MockSpotifyClient implements SpotifyClientContract
{
    public function search(string $query, int $limit = 10): array
    {
        return [];
    }

    public function getDevices(): array
    {
        return [
            ['id' => 'demo-living-room', 'name' => 'Living room speaker (demo)', 'type' => 'Speaker', 'is_active' => true],
        ];
    }

    public function playTrack(string $trackUri, ?string $deviceId, int $positionMs = 0): bool
    {
        return true;
    }

    public function pause(?string $deviceId = null): bool
    {
        return true;
    }

    public function seek(int $positionMs, ?string $deviceId = null): bool
    {
        return true;
    }

    public function setVolume(int $percent, ?string $deviceId = null): bool
    {
        return true;
    }

    public function searchPlaylists(string $query, int $limit = 8): array
    {
        return [];
    }

    public function myPlaylists(int $limit = 50): array
    {
        return [];
    }

    public function getPlaylist(string $id): ?array
    {
        return null;
    }

    public function playContext(string $contextUri, ?string $deviceId, bool $shuffle = true): bool
    {
        return true;
    }

    public function getPlaybackState(): ?array
    {
        return null;
    }
}
