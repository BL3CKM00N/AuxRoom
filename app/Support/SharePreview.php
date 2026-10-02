<?php

namespace App\Support;

use App\Models\Room;
use App\Services\ShareImage;

/**
 * What a shared link to a page should say in a chat or social preview, so the
 * card describes the page the link actually opens (and carries the same URL,
 * invite code included) rather than being one generic card for everything.
 *
 * Only what someone holding the link would see on opening it is used: the
 * host's name, never the room's current track or members.
 */
final class SharePreview
{
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $url,
        public readonly bool $noindex = false,
        public readonly ?string $image = null,
        public readonly ?string $imageSquare = null,
    ) {}

    /** The invite page. An unknown or closed code gets an honest "ended" card instead of an invitation. */
    public static function forJoin(?string $code): self
    {
        $code = strtoupper(trim((string) $code));
        $room = $code === '' ? null : Room::with('host')->where('invite_code', $code)->whereNull('closed_at')->first();

        if ($code === '') {
            return new self(
                'Join a room on AuxRoom',
                "Enter the invite code from the host's screen to join a shared listening room. No Spotify account needed.",
                route('join'),
            );
        }

        if (! $room) {
            return new self(
                'This AuxRoom has ended',
                'The invite code no longer matches an open room. Ask the host for a new one, or start your own room.',
                route('join', ['code' => $code]),
                noindex: true,
            );
        }

        return new self(
            self::hostName($room).' invited you to their AuxRoom',
            'Join the shared listening room and add songs to the queue. No Spotify account needed.',
            route('join', ['code' => $room->invite_code]),
            noindex: true,
            image: self::cardUrl($room, 'wide'),
            imageSquare: self::cardUrl($room, 'square'),
        );
    }

    /** The version in the query changes with the host's name, so a platform that cached the old picture fetches the new one. */
    private static function cardUrl(Room $room, string $shape): string
    {
        return route('share.image', [
            'code' => $room->invite_code,
            'shape' => $shape,
            'v' => substr(md5(ShareImage::drawableName((string) $room->host?->name)), 0, 8),
        ]);
    }

    /** The big-screen display for a room: open to anyone with the link, no sign-in. */
    public static function forParty(Room $room): self
    {
        return new self(
            self::hostName($room)."'s AuxRoom party screen",
            'The live now-playing display for the room. Scan the code on it to join and add a song.',
            route('rooms.party', $room),
            noindex: true,
        );
    }

    private static function hostName(Room $room): string
    {
        return trim((string) $room->host?->name) ?: 'Someone';
    }
}
