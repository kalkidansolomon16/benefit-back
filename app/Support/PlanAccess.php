<?php

namespace App\Support;

use App\Models\MobileSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** What a member's current plan includes. */
class PlanAccess
{
    /** Premium training is included in premium / platinum plans. */
    public static function hasPremiumTraining(User $user): bool
    {
        $sub = MobileSubscription::with('plan')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('end_date', '>=', now()->toDateString())
            ->latest()
            ->first();

        $plan = $sub?->plan
            ? [$sub->plan->name, $sub->plan->tier]
            : (array) DB::table('memberships')
                ->join('employees', 'memberships.employee_id', '=', 'employees.id')
                ->join('membership_plans', 'memberships.plan_id', '=', 'membership_plans.id')
                ->where('employees.user_id', $user->id)
                ->where('memberships.status', 'active')
                ->where('memberships.end_date', '>=', now()->toDateString())
                ->select('membership_plans.name', 'membership_plans.tier')
                ->first();

        if (!$sub && $user->employee && !$user->employee->hasPaidAccess()) {
            return false;
        }

        $s = strtolower(implode(' ', array_filter(array_values($plan))));

        return str_contains($s, 'premium') || str_contains($s, 'platinum');
    }
}
