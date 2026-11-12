<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Path to the uploaded PDF or image of the renewed business licence
            $table->string('business_license_path')->nullable()->after('tin_number');

            // Internal review status set by FitAccess admin
            $table->enum('business_license_status', ['pending', 'approved', 'rejected', 'expired'])
                  ->default('pending')
                  ->after('business_license_path');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['business_license_path', 'business_license_status']);
        });
    }
};
