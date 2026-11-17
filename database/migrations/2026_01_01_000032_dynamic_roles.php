<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        // 1. Change role column from ENUM to VARCHAR so any role slug can be stored
        DB::statement("ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(60) NOT NULL DEFAULT 'employee'");

        // 2. Roles catalogue
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->comment('Slug stored in users.role');
            $table->string('label', 80);
            $table->enum('scope', ['admin', 'company', 'gym']);
            // NULL = global (admin scope); set for company/gym custom roles
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('gym_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_system')->default(false)->comment('Cannot be deleted');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 3. Seed system roles
        $now = now();

        // Admin scope
        $adminRoles = [
            ['fitaccess_admin', 'Admin',    'admin', true],
            ['admin_finance',   'Finance',  'admin', true],
            ['admin_support',   'Support',  'admin', true],
        ];
        foreach ($adminRoles as [$name, $label, $scope, $sys]) {
            DB::table('roles')->insert([
                'name' => $name, 'label' => $label,
                'scope' => $scope, 'is_system' => $sys,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Company scope — system roles shared across all companies (company_id = null)
        $companyRoles = [
            ['company_hr',      'HR',      'company', true],
            ['company_finance',  'Finance', 'company', true],
            ['company_ceo',      'CEO',     'company', true],
        ];
        foreach ($companyRoles as [$name, $label, $scope, $sys]) {
            DB::table('roles')->insert([
                'name' => $name, 'label' => $label,
                'scope' => $scope, 'is_system' => $sys,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Gym scope
        $gymRoles = [
            ['gym_partner', 'Owner',            'gym', true],
            ['gym_staff',   'Check-in Staff',   'gym', true],
        ];
        foreach ($gymRoles as [$name, $label, $scope, $sys]) {
            DB::table('roles')->insert([
                'name' => $name, 'label' => $label,
                'scope' => $scope, 'is_system' => $sys,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');

        // Revert to previous VARCHAR (not restoring ENUM to avoid data issues)
        DB::statement("ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(60) NOT NULL DEFAULT 'employee'");
    }
};
