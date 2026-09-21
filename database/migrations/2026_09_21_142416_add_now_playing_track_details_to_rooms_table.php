<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Now Playing used to be reconstructed from a locally-created QueueItem
     * per track, ordered by a position number we kept decrementing to sort
     * new arrivals to the top. That broke as soon as playback could move
     * backward (previous) as well as forward — positions stopped meaning
     * "order", old entries never got marked played correctly, and guest
     * adds got miscategorized by the same broken math. Spotify's own live
     * state is the only reliable source for "what's playing right now" and
     * "what's next" — these columns hold that directly, no local
     * reconstruction involved. QueueItem stays, but purely as a log of
     * what a guest explicitly added, for attribution.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('now_playing_track_id')->nullable()->after('now_playing_queue_item_id');
            $table->string('now_playing_name')->nullable()->after('now_playing_track_id');
            $table->string('now_playing_artist')->nullable()->after('now_playing_name');
            $table->string('now_playing_album_art_url')->nullable()->after('now_playing_artist');
            $table->unsignedInteger('now_playing_duration_ms')->nullable()->after('now_playing_album_art_url');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn([
                'now_playing_track_id',
                'now_playing_name',
                'now_playing_artist',
                'now_playing_album_art_url',
                'now_playing_duration_ms',
            ]);
        });
    }
};
