<?php

namespace Database\Seeders;

use App\Models\MembershipPlan;
use Illuminate\Database\Seeder;

class MembershipPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name'            => 'Fit Platinum',
                'tier'            => 'platinum',
                'monthly_fee_etb' => 19600.00,
                'duration_months' => 1,
                'target_level'    => 'chief',
                'features'        => ['Premium gym access', 'Spa & sauna', 'Group & private classes', 'Priority booking', 'Nutritionist access'],
                'is_active'       => true,
            ],
            [
                'name'            => 'Fit Basic Plus',
                'tier'            => 'basic_plus',
                'monthly_fee_etb' => 7200.00,
                'duration_months' => 1,
                'target_level'    => 'director',
                'features'        => ['Mid-tier gym access', 'Group classes', 'Monthly wellness report', 'App access'],
                'is_active'       => true,
            ],
            [
                'name'            => 'Fit Basic',
                'tier'            => 'basic',
                'monthly_fee_etb' => 3800.00,
                'duration_months' => 1,
                'target_level'    => 'staff',
                'features'        => ['Basic gym access', 'Standard equipment', 'Locker room'],
                'is_active'       => true,
            ],
        ];

        foreach ($plans as $plan) {
            MembershipPlan::firstOrCreate(['name' => $plan['name']], $plan);
        }
    }
}
