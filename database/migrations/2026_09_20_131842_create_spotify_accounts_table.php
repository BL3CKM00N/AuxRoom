<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spotify_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Each user brings their own Spotify Developer app — no shared public app.
            $table->string('client_id');
            $table->text('client_secret');

            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->text('scopes')->nullable();

            $table->string('spotify_user_id')->nullable();
            $table->string('display_name')->nullable();
            $table->string('avatar_url')->nullable();

            $table->string('active_device_id')->nullable();
            $table->string('active_device_name')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spotify_accounts');
    }
};
