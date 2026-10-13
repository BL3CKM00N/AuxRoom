<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            // {src: sha1 of the art url it was made from, h: hue 0-359 or null, s: vibrance 0-100}
            $table->json('now_playing_tint')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', fn (Blueprint $table) => $table->dropColumn('now_playing_tint'));
    }
};
