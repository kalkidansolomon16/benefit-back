<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('company_status', ['active', 'suspended', 'banned'])
                  ->default('active')
                  ->after('is_active');
            $table->text('suspension_reason')->nullable()->after('company_status');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['company_status', 'suspension_reason']);
        });
    }
};
