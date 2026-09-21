<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('invite_code', 20)->unique();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();

            // Whichever member's Spotify account is currently the playback source.
            $table->foreignId('playback_provider_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_private')->default(true);
            $table->boolean('shared_controls_enabled')->default(true);

            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();
            $table->unsignedInteger('location_radius_m')->nullable();
            $table->boolean('location_enforced')->default(false);

            $table->timestamp('closed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
