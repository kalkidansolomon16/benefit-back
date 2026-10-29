<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Tracks the last billing_period (e.g. "August 2026") this employee's
            // company invoice was verified/paid for. Unlike payment_status (a single
            // sticky flag), this lets invoice generation re-arm billing every new
            // period instead of treating a past payment as covering all future ones.
            $table->string('paid_period', 30)->nullable()->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('paid_period');
        });
    }
};
