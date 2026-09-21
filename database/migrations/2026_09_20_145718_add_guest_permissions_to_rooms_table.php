<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->boolean('guests_can_add_to_queue')->default(true)->after('shared_controls_enabled');
            $table->boolean('guests_can_play_pause')->default(true)->after('guests_can_add_to_queue');
            $table->boolean('guests_can_skip')->default(true)->after('guests_can_play_pause');
            $table->boolean('guests_can_seek')->default(true)->after('guests_can_skip');
            $table->boolean('guests_can_set_volume')->default(true)->after('guests_can_seek');
        });

        // Carry over the old blanket toggle as the starting value for every new permission.
        DB::table('rooms')->update([
            'guests_can_add_to_queue' => DB::raw('shared_controls_enabled'),
            'guests_can_play_pause' => DB::raw('shared_controls_enabled'),
            'guests_can_skip' => DB::raw('shared_controls_enabled'),
            'guests_can_seek' => DB::raw('shared_controls_enabled'),
            'guests_can_set_volume' => DB::raw('shared_controls_enabled'),
        ]);

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('shared_controls_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->boolean('shared_controls_enabled')->default(true);
        });

        DB::table('rooms')->update([
            'shared_controls_enabled' => DB::raw('guests_can_add_to_queue'),
        ]);

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn([
                'guests_can_add_to_queue',
                'guests_can_play_pause',
                'guests_can_skip',
                'guests_can_seek',
                'guests_can_set_volume',
            ]);
        });
    }
};
