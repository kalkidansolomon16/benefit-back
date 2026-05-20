<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE `users` MODIFY COLUMN `role` ENUM(
            'super_admin',
            'fitaccess_admin',
            'company_hr',
            'employee',
            'gym_staff',
            'gym_partner'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `users` MODIFY COLUMN `role` ENUM(
            'super_admin',
            'fitaccess_admin',
            'company_hr',
            'employee',
            'gym_staff'
        ) NOT NULL");
    }
};
