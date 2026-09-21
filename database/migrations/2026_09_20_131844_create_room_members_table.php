<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();

            // Nullable: guests need no account at all, identified by a signed cookie token instead.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('guest_token', 64)->nullable()->unique();

            $table->string('display_name');
            $table->enum('role', ['host', 'guest'])->default('guest');

            $table->timestamp('location_verified_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('left_at')->nullable();

            $table->timestamps();

            $table->index(['room_id', 'left_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_members');
    }
};
