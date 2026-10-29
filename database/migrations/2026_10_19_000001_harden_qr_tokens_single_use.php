<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INSA finding CWE-345 (/partner/scan): QR tokens were long-lived, reusable
 * bearer secrets stored in plaintext. From now on tokens are short-lived,
 * single-use and stored only as a SHA-256 hash.
 *
 * Every existing token is plaintext and long-lived, so all of them are
 * revoked. Members get a fresh code the next time their QR screen loads.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('qr_tokens')->delete();

        Schema::table('qr_tokens', function (Blueprint $table) {
            $table->timestamp('used_at')->nullable()->after('expires_at');
            $table->foreignId('used_at_gym_id')->nullable()->after('used_at')
                ->constrained('gyms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('qr_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('used_at_gym_id');
            $table->dropColumn('used_at');
        });
    }
};
