<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Services\ShareImage;
use Illuminate\Http\Response;

class ShareImageController extends Controller
{
    /** The preview picture for an open room's invite link. Closed or unknown codes have none. */
    public function show(string $code, string $shape, ShareImage $images): Response
    {
        abort_unless(isset(ShareImage::SHAPES[$shape]), 404);

        $room = Room::with('host')->where('invite_code', strtoupper(trim($code)))->whereNull('closed_at')->first();

        abort_unless($room, 404);

        return response($images->jpeg($shape, (string) $room->host?->name), 200, [
            'Content-Type' => 'image/jpeg',
            // Platforms and CDNs may keep it; the URL changes when the host's name does (see SharePreview).
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
