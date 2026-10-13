<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            // Null means the rule is off.
            $table->unsignedTinyInteger('queue_limit_per_guest')->nullable();
            $table->unsignedSmallInteger('duplicate_guard_tracks')->nullable();
            $table->boolean('block_explicit')->default(false);
            $table->json('now_playing_artists')->nullable(); // [{id, name}]
            $table->boolean('now_playing_explicit')->default(false);
        });

        Schema::table('room_members', function (Blueprint $table) {
            $table->boolean('queue_rules_exempt')->default(false);
        });

        Schema::table('queue_items', function (Blueprint $table) {
            $table->json('artists')->nullable(); // [{id, name}]
            $table->boolean('explicit')->default(false);
        });

        Schema::create('blocked_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // track | artist
            $table->string('spotify_id');
            $table->string('name');
            $table->string('detail')->nullable(); // the artist line for a track
            $table->timestamps();

            $table->unique(['room_id', 'kind', 'spotify_id']);
        });

        // What has played in the room, for the duplicate guard. Includes playlist songs nobody queued.
        Schema::create('played_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('spotify_track_id');
            $table->timestamp('played_at');

            $table->index(['room_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('played_tracks');
        Schema::dropIfExists('blocked_items');

        Schema::table('queue_items', fn (Blueprint $t) => $t->dropColumn(['artists', 'explicit']));
        Schema::table('room_members', fn (Blueprint $t) => $t->dropColumn('queue_rules_exempt'));
        Schema::table('rooms', fn (Blueprint $t) => $t->dropColumn(['queue_limit_per_guest', 'duplicate_guard_tracks', 'block_explicit', 'now_playing_artists', 'now_playing_explicit']));
    }
};
