<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Set when Spotify permanently rejects the stored credentials (revoked
     * access, regenerated client secret): no amount of retrying fixes that,
     * only the owner reconnecting does. Kept separate from access_token on
     * purpose, since clearing the token would make the room look "not
     * connected" and silently drop every guest into the demo room.
     */
    public function up(): void
    {
        Schema::table('spotify_accounts', function (Blueprint $table) {
            $table->timestamp('needs_reconnect_at')->nullable()->after('token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('spotify_accounts', function (Blueprint $table) {
            $table->dropColumn('needs_reconnect_at');
        });
    }
};
