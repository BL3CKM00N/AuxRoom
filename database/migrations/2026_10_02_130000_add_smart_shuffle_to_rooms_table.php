<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spotify's Smart Shuffle: shuffle that also mixes in recommended songs
     * that aren't in the playlist. The player state reports it as
     * `smart_shuffle` (undocumented), alongside `shuffle_state`.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->boolean('smart_shuffle_enabled')->default(false)->after('shuffle_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('smart_shuffle_enabled');
        });
    }
};
