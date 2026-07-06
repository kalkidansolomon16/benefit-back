<?php

namespace Database\Seeders;

use App\Models\MembershipPlan;
use App\Models\MobileSubscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MemberUserSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            'platinum'  => MembershipPlan::where('tier', 'platinum')->first(),
            'basic_plus'=> MembershipPlan::where('tier', 'basic_plus')->first(),
            'basic'     => MembershipPlan::where('tier', 'basic')->first(),
        ];

        foreach ($plans as $tier => $plan) {
            if (!$plan) {
                $this->command->warn("No $tier plan found — run MembershipPlanSeeder first.");
            }
        }

        $members = [
            [
                'name'     => 'Kaleb Mekonnen',
                'email'    => 'kaleb@demo.et',
                'phone'    => '+251911100001',
                'password' => 'Password@123',
                'plan_tier'=> 'platinum',
                'billing_cycle' => 'monthly',
            ],
            [
                'name'     => 'Hana Girma',
                'email'    => 'hana@demo.et',
                'phone'    => '+251911100002',
                'password' => 'Password@123',
                'plan_tier'=> 'basic_plus',
                'billing_cycle' => 'monthly',
            ],
            [
                'name'     => 'Natnael Bekele',
                'email'    => 'natnael@demo.et',
                'phone'    => '+251911100003',
                'password' => 'Password@123',
                'plan_tier'=> 'basic',
                'billing_cycle' => 'monthly',
            ],
        ];

        foreach ($members as $memberData) {
            $plan = $plans[$memberData['plan_tier']];

            // Create or update user
            $user = User::updateOrCreate(
                ['email' => $memberData['email']],
                [
                    'name'        => $memberData['name'],
                    'password'    => Hash::make($memberData['password']),
                    'role'        => 'member',
                    'phone'       => $memberData['phone'],
                    'is_active'   => true,
                    'member_code' => 'FA-' . strtoupper(Str::random(5)),
                ]
            );

            // Cancel any existing active subscription
            MobileSubscription::where('user_id', $user->id)
                ->where('status', 'active')
                ->update(['status' => 'cancelled']);

            // Create active subscription
            if ($plan) {
                $start = now();
                $end   = now()->addMonths($plan->duration_months ?? 1);

                MobileSubscription::updateOrCreate(
                    ['user_id' => $user->id, 'plan_id' => $plan->id],
                    [
                        'status'            => 'active',
                        'billing_cycle'     => $memberData['billing_cycle'],
                        'amount_paid'       => $plan->monthly_fee_etb,
                        'payment_reference' => 'DEMO-' . strtoupper(Str::random(8)),
                        'start_date'        => $start,
                        'end_date'          => $end,
                        'auto_renew'        => true,
                    ]
                );

                $this->command->info("✓ {$memberData['name']} ({$memberData['email']}) — {$plan->name} plan, expires {$end->toDateString()}");
            } else {
                $this->command->info("✓ {$memberData['name']} ({$memberData['email']}) — no plan assigned (plan missing)");
            }
        }

        $this->command->newLine();
        $this->command->info('All demo members use password: Password@123');
    }
}
