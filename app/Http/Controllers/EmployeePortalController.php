<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Gym;
use App\Models\MembershipPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class EmployeePortalController extends Controller
{
    private function getMyEmployee(): Employee
    {
        $employee = Employee::where('user_id', auth()->id())
            ->with(['user', 'company', 'activeMembership', 'checkins.gym'])
            ->first();

        if (!$employee) {
            abort(403, 'No employee record linked to this account.');
        }

        return $employee;
    }

    public function dashboard(): JsonResponse
    {
        $employee = $this->getMyEmployee();
        $user     = $employee->user;
        $company  = $employee->company;
        $membership = $employee->activeMembership;

        // ── Resolve plan dynamically from membership_plans table ────
        $levelToTier = ['chief' => 'platinum', 'director' => 'basic_plus', 'manager' => 'basic', 'staff' => 'basic'];
        $employeeLevel = $employee->level ?? 'staff';
        $fallbackTier  = $levelToTier[$employeeLevel] ?? 'basic';

        // Try to find the plan by tier key, then by target_level, else cheapest active plan
        $myPlan = MembershipPlan::where('tier', $fallbackTier)->where('is_active', true)->first()
               ?? MembershipPlan::where('target_level', $employeeLevel)->where('is_active', true)->first()
               ?? MembershipPlan::where('is_active', true)->orderBy('monthly_fee_etb')->first();

        $planKey  = $myPlan?->tier  ?? $fallbackTier;
        $planName = $myPlan?->name  ?? ucfirst(str_replace('_', ' ', $planKey));

        // Tier hierarchy: higher rank includes all lower tiers
        // Only standard tiers are included — custom/test plans are excluded from gym access
        $tierRank = ['basic' => 0, 'basic_plus' => 1, 'premium' => 2, 'platinum' => 3];
        $myRank   = $tierRank[$planKey] ?? 0;

        $accessibleTiers = array_keys(array_filter(
            $tierRank,
            fn($rank) => $rank <= $myRank
        ));

        // Fallback for unrecognised tier keys
        if (empty($accessibleTiers)) {
            $accessibleTiers = [$planKey];
        }

        // Fetch active plans to build labels (only standard tiers)
        $allActivePlans = MembershipPlan::where('is_active', true)->orderBy('monthly_fee_etb')->get();

        // Accessible plan names (only the standard tiers that match)
        $accessiblePlanLabels = $allActivePlans
            ->filter(fn($p) => in_array($p->tier, $accessibleTiers) && isset($tierRank[$p->tier]))
            ->mapWithKeys(fn($p) => [$p->tier => $p->name])
            ->toArray();

        // ── Check-in stats ──────────────────────────────────────────
        $allCheckins   = $employee->checkins;
        $now           = Carbon::now();
        $thisMonthCheckins = $allCheckins->filter(fn($c) => $c->checked_in_at?->isCurrentMonth())->count();
        $thisWeekCheckins  = $allCheckins->filter(fn($c) => $c->checked_in_at?->isCurrentWeek())->count();
        $lastCheckin       = $allCheckins->sortByDesc('checked_in_at')->first();

        // ── Recent check-ins (last 8) ───────────────────────────────
        $recentCheckins = $allCheckins
            ->sortByDesc('checked_in_at')
            ->take(8)
            ->map(fn($c) => [
                'id'             => $c->id,
                'gym_name'       => $c->gym?->name ?? 'Unknown Gym',
                'gym_tier'       => $c->gym?->tier ?? 'basic',
                'gym_sub_city'   => $c->gym?->sub_city ?? '',
                'checked_in_at'  => $c->checked_in_at?->format('Y-m-d H:i'),
                'checked_out_at' => $c->checked_out_at?->format('Y-m-d H:i'),
                'duration_min'   => $c->duration_minutes,
            ])->values();

        // ── Accessible gyms based on plan ───────────────────────────
        $gyms = Gym::where('is_active', true)
            ->whereIn('tier', $accessibleTiers)
            ->orderBy('name')
            ->get(['id', 'name', 'tier', 'sub_city', 'city', 'address', 'facilities', 'opening_hours'])
            ->map(fn($g) => [
                'id'           => $g->id,
                'name'         => $g->name,
                'tier'         => $g->tier,
                'sub_city'     => $g->sub_city,
                'city'         => $g->city,
                'address'      => $g->address,
                'facilities'   => is_array($g->facilities) ? $g->facilities : [],
                'opening_hours'=> $g->opening_hours,
            ]);

        return response()->json([
            'profile' => [
                'name'       => $user?->name,
                'email'      => $user?->email,
                'phone'      => $user?->phone,
                'fan_number' => $employee->fan_number,
                'job_title'  => $employee->job_title,
                'department' => $employee->department,
                'branch'     => $employee->branch,
                'photo_path' => $employee->photo_path,
            ],
            'company' => [
                'name' => $company?->name,
                'city' => $company?->city,
            ],
            'pass' => [
                'plan_name'       => $planName,
                'plan_key'        => $planKey,
                'status'          => $membership?->status ?? ($employee->is_enrolled ? 'active' : 'inactive'),
                'valid_until'     => $membership?->end_date?->toDateString(),
                'accessible_tiers'=> $accessibleTiers,
                'plan_labels'     => $accessiblePlanLabels,
            ],
            'stats' => [
                'total_checkins'      => $allCheckins->count(),
                'this_month_checkins' => $thisMonthCheckins,
                'this_week_checkins'  => $thisWeekCheckins,
                'last_visit'          => $lastCheckin?->checked_in_at?->format('Y-m-d'),
            ],
            'recent_checkins' => $recentCheckins,
            'accessible_gyms' => $gyms,
        ]);
    }
}
