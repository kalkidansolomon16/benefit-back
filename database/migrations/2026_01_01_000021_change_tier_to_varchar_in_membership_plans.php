<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Change tier from ENUM to VARCHAR so plans can have any custom tier key
        DB::statement('ALTER TABLE membership_plans MODIFY COLUMN tier VARCHAR(50) NOT NULL');
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE membership_plans MODIFY COLUMN tier ENUM('platinum','basic_plus','basic') NOT NULL");
    }
};
