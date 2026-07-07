<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('branch')->nullable()->after('department');
            $table->text('request_note')->nullable()->after('branch');
            $table->enum('registration_status', ['pending', 'approved', 'rejected'])
                  ->default('pending')->after('request_note');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['branch', 'request_note', 'registration_status']);
        });
    }
};
