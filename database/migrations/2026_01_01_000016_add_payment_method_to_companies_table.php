<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('preferred_payment_method', [
                'cbe_transfer',
                'telebirr_enterprise',
                'awash_bank',
                'chapa',
                'cash',
                'other',
            ])->nullable()->after('business_license_status');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('preferred_payment_method');
        });
    }
};
