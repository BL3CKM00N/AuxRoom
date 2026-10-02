<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the active playback device can be volume-controlled. Spotify
     * reports `supports_volume: false` for some phones and speakers; the
     * volume slider is disabled for those, since the command would do nothing.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->boolean('volume_supported')->default(true)->after('volume_percent');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('volume_supported');
        });
    }
};
