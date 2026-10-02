<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * now_playing_context_uri: the playlist/album Spotify is really playing
     * from, whoever started it (a playlist begun in the Spotify app is never
     * stored as the room's own playlist). Lets "Coming up" be trimmed to the
     * real remainder and lets a click on a track jump inside that playlist.
     *
     * playlist_finished_at: set when a playlist ran out with repeat off.
     * Spotify then parks on the first track, paused, which would otherwise
     * look like a loop. Cleared as soon as anything plays again.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('now_playing_context_uri')->nullable()->after('now_playing_track_id');
            $table->timestamp('playlist_finished_at')->nullable()->after('playback_inactive_at');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['now_playing_context_uri', 'playlist_finished_at']);
        });
    }
};
