<?php

namespace App\Services;

use App\Models\Checkin;
use App\Models\Employee;
use App\Models\Gym;
use App\Models\MobileSubscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who may check in at a gym, and recording the visit. Shared by the QR /
 * front-desk-code scan and the v2 manual check-in, so every path applies
 * the same rules (INSA CWE-345): active account, active plan (or a company
 * membership whose invoice covers today), gym tier, 12 hours between
 * visits at the same gym and a monthly cap.
 */
class CheckinService
{
    public const MAX_MONTHLY = 20; // default cap per gym; can be plan-specific

    /**
     * @return array{ok: true, plan_name: ?string, plan_tier: ?string, employee_id: ?int, membership_id: ?int}
     *       | array{ok: false, code: string, message: string}
     */
    public function eligibility(User $member, Gym $gym): array
    {
        if (!$member->is_active) {
            return ['ok' => false, 'code' => 'member_suspended', 'message' => 'Member account is suspended.'];
        }

        $subscription = MobileSubscription::with('plan')
            ->where('user_id', $member->id)
            ->where('status', 'active')
            ->where('end_date', '>=', now())
            ->latest()
            ->first();

        $employeeId = null;
        $membershipId = null;

        if ($subscription) {
            $planTier = $subscription->plan?->tier;
            $planName = $subscription->plan?->name;
        } else {
            $empMembership = DB::table('memberships')
                ->join('employees', 'memberships.employee_id', '=', 'employees.id')
                ->join('membership_plans', 'memberships.plan_id', '=', 'membership_plans.id')
                ->where('employees.user_id', $member->id)
                ->where('memberships.status', 'active')
                ->where('memberships.end_date', '>=', now()->toDateString())
                ->select('memberships.id as membership_id', 'memberships.employee_id', 'membership_plans.tier', 'membership_plans.name')
                ->first();

            if (!$empMembership) {
                return ['ok' => false, 'code' => 'no_subscription', 'message' => 'No active subscription. Member must purchase a plan.'];
            }

            $planTier = $empMembership->tier;
            $planName = $empMembership->name;
            $employeeId = $empMembership->employee_id;
            $membershipId = $empMembership->membership_id;

            // Company employees: only while their company's invoice covers today
            $emp = Employee::find($employeeId);
            if (!$emp || !$emp->hasPaidAccess()) {
                return ['ok' => false, 'code' => 'not_paid', 'message' => $emp ? $emp->paidAccessMessage() : 'No active FitAccess membership.'];
            }
        }

        // Plan tiers are free text (e.g. "Fit Premium"), gym tiers are enum keys
        // (e.g. "premium") — normalise both before ranking or every plan scores 0.
        $normalize = fn ($t) => preg_replace('/^fit_/', '', preg_replace('/[\s\-]+/', '_', strtolower(trim((string) $t))));
        $tierRank = ['basic' => 1, 'basic_plus' => 2, 'premium' => 3, 'platinum' => 3];
        $memberRank = $tierRank[$normalize($planTier)] ?? 0;
        $gymRank = $tierRank[$normalize($gym->tier)] ?? 0;

        if ($memberRank < $gymRank) {
            $required = match ($gym->tier) {
                'platinum', 'premium' => 'Platinum',
                'basic_plus' => 'Basic Plus or Platinum',
                default => 'Basic',
            };

            return ['ok' => false, 'code' => 'tier_too_low', 'message' => "This facility requires a {$required} plan. Member needs to upgrade."];
        }

        return ['ok' => true, 'plan_name' => $planName, 'plan_tier' => $planTier, 'employee_id' => $employeeId, 'membership_id' => $membershipId];
    }

    /**
     * Applies the visit rules and records the check-in in one locked step,
     * so two concurrent scans can't both pass.
     *
     * @param  array  $eligible  a successful eligibility() result
     * @param  callable|null  $extraCheck  runs inside the lock; return an error message to refuse
     * @return array{0: 'recorded', 1: Checkin, 2: int}|array{0: 'rejected', 1: string, 2: string}
     */
    public function record(Gym $gym, User $member, array $eligible, string $method, string $recordedBy, ?callable $extraCheck = null): array
    {
        $employeeId = $eligible['employee_id'];

        return DB::transaction(function () use ($gym, $member, $eligible, $method, $recordedBy, $employeeId, $extraCheck) {
            User::whereKey($member->id)->lockForUpdate()->first();

            if ($extraCheck && ($refusal = $extraCheck())) {
                return ['rejected', $refusal[0], $refusal[1]];
            }

            $memberVisits = fn () => Checkin::where('gym_id', $gym->id)
                ->where(function ($q) use ($member, $employeeId) {
                    $q->where('user_id', $member->id);
                    if ($employeeId) {
                        $q->orWhere('employee_id', $employeeId);
                    }
                });

            // 12-hour rule
            $lastCheckin = $memberVisits()->orderByDesc('checked_in_at')->first();
            if ($lastCheckin) {
                $hoursSince = Carbon::parse($lastCheckin->checked_in_at)->diffInMinutes(now()) / 60;
                if ($hoursSince < 12) {
                    $hoursRemaining = round(12 - $hoursSince, 1);

                    return ['rejected', 'cooldown', "You already checked in here today. You can check in again in {$hoursRemaining} hours."];
                }
            }

            // Monthly cap
            $monthlyCount = $memberVisits()
                ->whereYear('checked_in_at', now()->year)
                ->whereMonth('checked_in_at', now()->month)
                ->count();

            if ($monthlyCount >= self::MAX_MONTHLY) {
                return ['rejected', 'monthly_cap', "Monthly visit limit reached for this facility ({$monthlyCount} visits used). Limit resets on the 1st."];
            }

            $checkin = Checkin::create([
                'gym_id' => $gym->id,
                'employee_id' => $employeeId,
                'user_id' => $member->id,
                'checked_in_at' => now(),
                'method' => $method,
                'recorded_by' => $recordedBy,
                // membership_id references B2B `memberships`; B2C visits are linked by user_id.
                'membership_id' => $eligible['membership_id'],
            ]);

            return ['recorded', $checkin, $monthlyCount + 1];
        });
    }
}
