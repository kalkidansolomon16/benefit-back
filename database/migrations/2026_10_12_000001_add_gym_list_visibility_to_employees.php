<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('employees', 'show_in_gym_list')) {
            Schema::table('employees', function (Blueprint $table) {
                // Employees can hide their name from the "who is in the gym" list (still counted)
                $table->boolean('show_in_gym_list')->default(true)->after('is_enrolled');
            });
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('show_in_gym_list');
        });
    }
};
