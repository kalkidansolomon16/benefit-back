<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_reset_password')->default(false)->after('is_active');
            $table->string('password_reset_token')->nullable()->after('must_reset_password');
            $table->timestamp('password_reset_expires_at')->nullable()->after('password_reset_token');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->after('password_reset_expires_at');
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete()->after('created_by');
            $table->foreignId('gym_id')->nullable()->constrained()->nullOnDelete()->after('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['company_id']);
            $table->dropForeign(['gym_id']);
            $table->dropColumn([
                'must_reset_password', 'password_reset_token',
                'password_reset_expires_at', 'created_by',
                'company_id', 'gym_id',
            ]);
        });
    }
};
