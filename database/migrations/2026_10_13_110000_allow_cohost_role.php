<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The role column was an enum of host and guest; a co-host is a third value, so it becomes a plain string. */
    public function up(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->string('role', 10)->default('guest')->change();
        });

        // PostgreSQL keeps the enum's CHECK constraint after the column type changes.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table room_members drop constraint if exists room_members_role_check');
        }
    }

    public function down(): void
    {
        DB::table('room_members')->where('role', 'cohost')->update(['role' => 'guest']);
    }
};
