<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seed plans first
        $this->call(MembershipPlanSeeder::class);

        // Seed permissions + role defaults
        $this->call(PermissionsSeeder::class);

        // Create super admin — every run gets its own strong random password
        // (never a hardcoded/guessable one) and must change it on first login.
        $adminPassword = Str::password(16);
        $admin = User::firstOrCreate(
            ['email' => 'admin@fitaccess.com'],
            [
                'name'                 => 'FitAccess Admin',
                'password'             => Hash::make($adminPassword),
                'role'                 => 'super_admin',
                'is_active'            => true,
                'must_reset_password'  => true,
            ]
        );

        // Create a demo HR user
        $hrPassword = Str::password(16);
        $hr = User::firstOrCreate(
            ['email' => 'hr@demo.com'],
            [
                'name'                 => 'Demo HR Manager',
                'password'             => Hash::make($hrPassword),
                'role'                 => 'company_hr',
                'is_active'            => true,
                'must_reset_password'  => true,
            ]
        );

        $this->command->info('✅ FitAccess seed data created.');
        if ($admin->wasRecentlyCreated) {
            $this->command->warn("   Super Admin: admin@fitaccess.com / {$adminPassword}  (save this now — shown once, must change on first login)");
        }
        if ($hr->wasRecentlyCreated) {
            $this->command->warn("   Demo HR:     hr@demo.com / {$hrPassword}  (save this now — shown once, must change on first login)");
        }
    }
}
