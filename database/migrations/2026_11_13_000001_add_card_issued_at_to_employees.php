<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Card hand-over chain: FitAccess (admin) issues the card number to the
 * company first (card_issued_at); only then can HR send it to the employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('employees', 'card_issued_at')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->timestamp('card_issued_at')->nullable()->after('card_number');
            });
        }

        // Cards already activated were clearly handed over: keep them working
        DB::table('employees')
            ->whereNull('card_issued_at')
            ->whereNotNull('card_activated_at')
            ->update(['card_issued_at' => DB::raw('card_activated_at')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'card_issued_at')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('card_issued_at');
            });
        }
    }
};
