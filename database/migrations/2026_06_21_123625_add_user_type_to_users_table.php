<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('user_type', ['employee', 'company', 'gym'])->nullable()->after('role');
        });

        // Backfill existing users
        DB::table('users')->where('role', 'employee')->update(['user_type' => 'employee']);
        DB::table('users')->whereIn('role', ['company_hr', 'company_finance', 'company_ceo', 'co_hr', 'co_executive', 'co_finance'])->update(['user_type' => 'company']);
        DB::table('users')->whereIn('role', ['gym_partner', 'gym_staff', 'gym_hr', 'gym_executive', 'gym_finance'])->update(['user_type' => 'gym']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('user_type');
        });
    }
};
