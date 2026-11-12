<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INSA member-app finding CWE-613 (excessive token lifetime): members get a
 * 15-minute access token plus a rotating 7-day refresh token. Tokens of one
 * sign-in share a `family_id`, so the whole session can be revoked at once
 * (sign-out, revoking a device, or refresh-token reuse). `used_at` marks a
 * refresh token that has been exchanged; seeing it again means it was copied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->char('family_id', 36)->nullable()->after('abilities')->index();
            $table->timestamp('session_started_at')->nullable()->after('family_id');
            $table->timestamp('used_at')->nullable()->after('session_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['family_id']);
            $table->dropColumn(['family_id', 'session_started_at', 'used_at']);
        });
    }
};
