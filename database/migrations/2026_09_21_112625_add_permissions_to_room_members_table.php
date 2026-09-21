<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->boolean('can_add_to_queue')->default(false)->after('approved_at');
            $table->boolean('can_play_pause')->default(false)->after('can_add_to_queue');
            $table->boolean('can_skip')->default(false)->after('can_play_pause');
            $table->boolean('can_seek')->default(false)->after('can_skip');
            $table->boolean('can_set_volume')->default(false)->after('can_seek');
        });

        // Grandfather in guests who were already approved before this change —
        // they had full access under the old room-wide defaults, so a silent
        // migration shouldn't suddenly lock them out of everything.
        DB::table('room_members')
            ->where('role', 'guest')
            ->whereNotNull('approved_at')
            ->update([
                'can_add_to_queue' => true,
                'can_play_pause' => true,
                'can_skip' => true,
                'can_seek' => true,
                'can_set_volume' => true,
            ]);
    }

    public function down(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->dropColumn([
                'can_add_to_queue',
                'can_play_pause',
                'can_skip',
                'can_seek',
                'can_set_volume',
            ]);
        });
    }
};
