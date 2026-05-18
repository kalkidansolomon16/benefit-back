<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seed plans first
        $this->call(MembershipPlanSeeder::class);

        // Create super admin
        User::firstOrCreate(
            ['email' => 'admin@fitaccess.com'],
            [
                'name'      => 'FitAccess Admin',
                'password'  => Hash::make('password'),
                'role'      => 'super_admin',
                'is_active' => true,
            ]
        );

        // Create a demo HR user
        User::firstOrCreate(
            ['email' => 'hr@demo.com'],
            [
                'name'      => 'Demo HR Manager',
                'password'  => Hash::make('password'),
                'role'      => 'company_hr',
                'is_active' => true,
            ]
        );

        $this->command->info('✅ FitAccess seed data created.');
        $this->command->info('   Super Admin: admin@fitaccess.com / password');
        $this->command->info('   Demo HR:     hr@demo.com / password');
    }
}
