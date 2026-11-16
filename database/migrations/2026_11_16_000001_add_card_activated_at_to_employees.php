<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employee entered their 12-digit card number (emailed when the company
 * paid). Stored on the server so it's asked once per employee, in the app
 * and on the website alike.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('card_activated_at')->nullable()->after('card_number');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('card_activated_at');
        });
    }
};
