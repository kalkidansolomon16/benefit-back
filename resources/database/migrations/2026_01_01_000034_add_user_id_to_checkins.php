<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkins', function (Blueprint $table) {
            // Allow null on existing employee_id for B2C checkins
            $table->foreignId('user_id')
                ->nullable()
                ->after('employee_id')
                ->constrained()
                ->nullOnDelete();

            // Make employee_id nullable (B2C members have no employee record)
            $table->foreignId('employee_id')->nullable()->change();
            $table->foreignId('membership_id')->nullable()->change();

            // Add qr_code method to enum
            // MySQL: modify enum
            \DB::statement("ALTER TABLE checkins MODIFY COLUMN method ENUM('fan_number','card','facial','qr_code') DEFAULT 'fan_number'");

            $table->index(['user_id', 'checked_in_at']);
        });
    }

    public function down(): void
    {
        Schema::table('checkins', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
