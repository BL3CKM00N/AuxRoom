<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add-to-queue turned out to need one room-wide switch, not a per-guest
     * one — that's what the emergency stop's "unlock" expects to restore
     * instantly for everyone. Keeping both a room flag and a per-guest flag
     * for the same ability meant "unlock" could look like it did nothing.
     */
    public function up(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->dropColumn('can_add_to_queue');
        });
    }

    public function down(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->boolean('can_add_to_queue')->default(false)->after('approved_at');
        });
    }
};
