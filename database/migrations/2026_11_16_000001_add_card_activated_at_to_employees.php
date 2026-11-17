<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employee entered their 12-digit card number (emailed when the company
 * paid). Stored on the server so it's asked once per employee, in the app
 * and on the website alike.
 *
 * The website's own card activation (employee/activate-card) adds the same
 * column on the shared database, so only add it when it isn't there yet,
 * and never drop it on rollback (it isn't only ours).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'card_activated_at')) {
            return;
        }
        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('card_activated_at')->nullable()->after('card_number');
        });
    }

    public function down(): void
    {
        // Shared with the website's card activation: leave it in place.
    }
};
