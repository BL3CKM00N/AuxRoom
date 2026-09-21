<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->boolean('can_manage_playlist')->default(false)->after('can_set_volume');
        });
    }

    public function down(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->dropColumn('can_manage_playlist');
        });
    }
};
