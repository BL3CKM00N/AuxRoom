<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_items', function (Blueprint $table) {
            // Null released_at: the song is on AuxRoom's waiting list, not in Spotify's queue yet.
            $table->timestamp('released_at')->nullable();
            // Removed while already in Spotify's queue (which cannot take it back): skipped when it starts.
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
        });

        // Everything queued before this went straight to Spotify.
        DB::table('queue_items')->update(['released_at' => DB::raw('created_at')]);

        Schema::table('rooms', function (Blueprint $table) {
            $table->boolean('waiting_list_enabled')->default(false);
            $table->boolean('rotation_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('rooms', fn (Blueprint $table) => $table->dropColumn(['waiting_list_enabled', 'rotation_enabled']));
        Schema::table('queue_items', fn (Blueprint $table) => $table->dropColumn(['released_at', 'removed_at', 'skipped_at']));
    }
};
