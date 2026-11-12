<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->string('payment_channel', 20)->default('bank_transfer')->after('amount');
            $table->string('chapa_tx_ref', 100)->nullable()->after('payment_channel');
            $table->timestamp('chapa_verified_at')->nullable()->after('chapa_tx_ref');
        });
    }

    public function down(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->dropColumn(['payment_channel', 'chapa_tx_ref', 'chapa_verified_at']);
        });
    }
};
