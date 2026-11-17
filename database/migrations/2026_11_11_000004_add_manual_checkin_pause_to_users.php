<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2 manual check-in: set when staff log a photo / ID mismatch or the
 * member reports a manual check-in that wasn't them. While set, the
 * member can only check in with the code in their app.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('manual_checkins_paused_at')->nullable()->after('member_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('manual_checkins_paused_at');
        });
    }
};
