<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by_id')->nullable()->constrained('room_members')->nullOnDelete();

            $table->string('spotify_track_id');
            $table->string('name');
            $table->string('artist');
            $table->string('album_art_url')->nullable();
            $table->unsignedInteger('duration_ms');

            $table->unsignedInteger('position');
            $table->timestamp('played_at')->nullable();

            $table->timestamps();

            $table->index(['room_id', 'played_at', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_items');
    }
};
