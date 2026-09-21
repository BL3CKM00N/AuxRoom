<?php

namespace App\Services\Spotify;

interface SpotifyClientContract
{
    /**
     * @return array<int, array{id: string, uri: string, name: string, artist: string, album_art_url: ?string, duration_ms: int}>
     */
    public function search(string $query, int $limit = 10): array;

    /**
     * @return array<int, array{id: string, name: string, type: string, is_active: bool}>
     */
    public function getDevices(): array;

    public function playTrack(string $trackUri, ?string $deviceId, int $positionMs = 0): bool;

    public function pause(?string $deviceId = null): bool;

    public function seek(int $positionMs, ?string $deviceId = null): bool;

    public function setVolume(int $percent, ?string $deviceId = null): bool;

    /**
     * @return array<int, array{id: string, uri: string, name: string, owner: ?string, image_url: ?string, track_count: int}>
     */
    public function searchPlaylists(string $query, int $limit = 8): array;

    /**
     * @return array<int, array{id: string, uri: string, name: string, owner: ?string, image_url: ?string, track_count: int}>
     */
    public function myPlaylists(int $limit = 50): array;

    /**
     * @return array{id: string, uri: string, name: string, owner: ?string, image_url: ?string, track_count: int}|null
     */
    public function getPlaylist(string $id): ?array;

    public function playContext(string $contextUri, ?string $deviceId, bool $shuffle = true): bool;

    /**
     * The real, current playback state as Spotify sees it right now — used
     * to detect changes made outside AuxRoom (pausing/seeking from the
     * Spotify app itself, switching to a different track on another
     * device, etc). Includes full track metadata so the room can display
     * exactly what's playing even if it wasn't added through AuxRoom.
     *
     * @return array{is_playing: bool, progress_ms: int, track_id: ?string, device_id: ?string, name: ?string, artist: ?string, album_art_url: ?string, duration_ms: int}|null
     */
    public function getPlaybackState(): ?array;
}
