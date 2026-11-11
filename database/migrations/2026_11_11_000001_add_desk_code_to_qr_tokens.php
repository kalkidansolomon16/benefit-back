<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2 front-desk code: the 6 digits shown under a member's QR, which
 * reception can type in when the scan fails. It belongs to the same
 * single-use token, so redeeming either one uses up both.
 *
 * Only an HMAC of the code is stored (keyed with the app key): six digits
 * are too few to protect with a plain hash if the table ever leaks.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('qr_tokens', function (Blueprint $table) {
            $table->string('desk_code_hash', 64)->nullable()->after('token');
            $table->index(['desk_code_hash', 'used_at', 'expires_at'], 'qr_tokens_desk_code_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('qr_tokens', function (Blueprint $table) {
            $table->dropIndex('qr_tokens_desk_code_lookup');
            $table->dropColumn('desk_code_hash');
        });
    }
};
