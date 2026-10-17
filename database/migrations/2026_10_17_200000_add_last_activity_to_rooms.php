<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The last time a person did something in the room (not a screen refreshing itself): it sets how often Spotify is asked. */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable();
        });

        // Rooms that are open now start out as active, so none of them is put to sleep the moment this runs.
        DB::table('rooms')->whereNull('closed_at')->update(['last_activity_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('rooms', fn (Blueprint $table) => $table->dropColumn('last_activity_at'));
    }
};
