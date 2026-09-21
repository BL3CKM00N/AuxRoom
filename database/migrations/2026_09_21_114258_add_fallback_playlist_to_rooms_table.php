<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('fallback_playlist_uri')->nullable()->after('guests_can_add_to_queue');
            $table->string('fallback_playlist_name')->nullable()->after('fallback_playlist_uri');
            $table->string('fallback_playlist_image_url')->nullable()->after('fallback_playlist_name');
            $table->boolean('is_playing_fallback')->default(false)->after('fallback_playlist_image_url');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn([
                'fallback_playlist_uri',
                'fallback_playlist_name',
                'fallback_playlist_image_url',
                'is_playing_fallback',
            ]);
        });
    }
};
