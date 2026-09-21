<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->boolean('shuffle_enabled')->default(false)->after('now_playing_duration_ms');
            $table->string('repeat_mode')->default('off')->after('shuffle_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['shuffle_enabled', 'repeat_mode']);
        });
    }
};
