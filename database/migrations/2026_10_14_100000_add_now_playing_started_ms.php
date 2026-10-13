<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * now_playing_started_at is a whole-second timestamp, so every position built from it was up to a second
     * off. The same moment, in milliseconds, is kept next to it and used for the position.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedBigInteger('now_playing_started_ms')->nullable();
        });

        DB::table('rooms')->whereNotNull('now_playing_started_at')->orderBy('id')->each(function ($room) {
            DB::table('rooms')->where('id', $room->id)->update([
                'now_playing_started_ms' => Carbon::parse($room->now_playing_started_at)->getTimestamp() * 1000,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('rooms', fn (Blueprint $table) => $table->dropColumn('now_playing_started_ms'));
    }
};
