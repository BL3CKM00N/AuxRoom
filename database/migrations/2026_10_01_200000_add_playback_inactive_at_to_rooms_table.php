<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Set when Spotify reports nothing active anywhere (no device left to
     * play on). The last track and position are kept so Play can still
     * resume it the moment a device is back, but the room stops presenting
     * that track as what's playing, so a dead track isn't shown as
     * "paused" indefinitely. Cleared as soon as anything plays again.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->timestamp('playback_inactive_at')->nullable()->after('is_playing');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('playback_inactive_at');
        });
    }
};
