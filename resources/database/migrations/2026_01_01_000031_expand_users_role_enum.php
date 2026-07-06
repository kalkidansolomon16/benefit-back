<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // ALTER the ENUM to include all roles (MySQL requires listing ALL values)
        DB::statement("
            ALTER TABLE `users`
            MODIFY COLUMN `role` ENUM(
                'super_admin',
                'fitaccess_admin',
                'admin_finance',
                'admin_support',
                'company_hr',
                'company_finance',
                'company_ceo',
                'employee',
                'gym_partner',
                'gym_staff'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE `users`
            MODIFY COLUMN `role` ENUM(
                'super_admin',
                'fitaccess_admin',
                'company_hr',
                'employee',
                'gym_staff'
            ) NOT NULL
        ");
    }
};
