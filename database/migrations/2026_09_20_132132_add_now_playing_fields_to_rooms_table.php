<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->foreignId('now_playing_queue_item_id')->nullable()->after('playback_provider_id')
                ->constrained('queue_items')->nullOnDelete();
            $table->timestamp('now_playing_started_at')->nullable()->after('now_playing_queue_item_id');
            $table->unsignedInteger('now_playing_position_ms')->default(0)->after('now_playing_started_at');
            $table->boolean('is_playing')->default(false)->after('now_playing_position_ms');
            $table->unsignedTinyInteger('volume_percent')->default(70)->after('is_playing');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('now_playing_queue_item_id');
            $table->dropColumn(['now_playing_started_at', 'now_playing_position_ms', 'is_playing', 'volume_percent']);
        });
    }
};
