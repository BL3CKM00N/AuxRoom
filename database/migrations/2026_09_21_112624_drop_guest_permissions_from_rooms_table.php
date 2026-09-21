<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * These were the room-wide defaults for every guest. They're being
     * replaced by per-guest permissions on room_members — a single global
     * switch meant every guest either had an ability or none did, which
     * didn't work once hosts wanted to trust some guests more than others.
     * guests_can_add_to_queue stays: it now serves only as the emergency
     * stop's room-wide lock, layered on top of each guest's own permission.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn([
                'guests_can_play_pause',
                'guests_can_skip',
                'guests_can_seek',
                'guests_can_set_volume',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->boolean('guests_can_play_pause')->default(true)->after('guests_can_add_to_queue');
            $table->boolean('guests_can_skip')->default(true)->after('guests_can_play_pause');
            $table->boolean('guests_can_seek')->default(true)->after('guests_can_skip');
            $table->boolean('guests_can_set_volume')->default(true)->after('guests_can_seek');
        });
    }
};
