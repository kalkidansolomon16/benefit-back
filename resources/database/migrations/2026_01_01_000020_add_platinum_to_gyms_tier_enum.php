<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE `gyms` MODIFY COLUMN `tier` ENUM('basic','basic_plus','premium','platinum') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `gyms` MODIFY COLUMN `tier` ENUM('premium','basic_plus','basic') NOT NULL");
    }
};
