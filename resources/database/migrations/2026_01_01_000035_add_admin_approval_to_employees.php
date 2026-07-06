<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->enum('admin_approval_status', ['pending', 'approved', 'rejected'])
                  ->nullable()
                  ->after('payment_status');
            $table->enum('payment_preference', ['pay_now', 'pay_later'])
                  ->nullable()
                  ->after('admin_approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['admin_approval_status', 'payment_preference']);
        });
    }
};
