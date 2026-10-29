<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Gym;
use App\Models\MembershipPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $canonicalTier = $levelToTier[$employeeLevel] ?? 'basic';

        $allActivePlans = MembershipPlan::where('is_active', true)->orderBy('monthly_fee_etb')->get();

        // Plan tiers are typed by admins ("Fit Basic", "fit_basic_plus"), so compare them in a
        // normalised form: lower-case, every run of non-alphanumerics turned into "_".
        $norm = fn(string $t): string => trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($t)), '_');

        // Flexible plan finder: exact match → suffix match (e.g. 'fit_basic' ends with '_basic') → cheapest
        $myPlan = $allActivePlans->first(fn($p) => $norm($p->tier) === $canonicalTier)
            ?? $allActivePlans->first(fn($p) => str_ends_with($norm($p->tier), '_' . $canonicalTier))
            ?? $allActivePlans->first();

        $planKey  = $myPlan?->tier  ?? $canonicalTier;
        $planName = $myPlan?->name  ?? ucfirst(str_replace('_', ' ', $canonicalTier));
        $planFee  = (float) ($myPlan?->monthly_fee_etb ?? 0);

        // Normalize a plan tier key to a canonical gym tier (strips brand prefix).
        // e.g. 'fit_basic' → 'basic', 'fit_basic_plus' → 'basic_plus', 'fit_premium' → 'premium'
        $gymTierOf = function (string $tier) use ($norm): string {
            $tier = $norm($tier);
            foreach (['platinum', 'premium', 'basic_plus', 'basic'] as $c) {
                if ($tier === $c || str_ends_with($tier, '_' . $c)) return $c;
            }
            return $tier;
        };

        $myGymTier = $gymTierOf($planKey);

        // Only show gyms that match the employee's own plan tier exactly
        $accessibleTiers = [$myGymTier];

        // Plan label for the employee's own tier
        $accessiblePlanLabels = $allActivePlans
            ->filter(fn($p) => $gymTierOf($p->tier) === $myGymTier)
            ->mapWithKeys(fn($p) => [$gymTierOf($p->tier) => $p->name])
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
        $foundGyms = Gym::where('is_active', true)
            ->whereIn('tier', $accessibleTiers)
            ->orderBy('name')
            ->get(['id', 'name', 'tier', 'sub_city', 'city', 'address', 'facilities', 'opening_hours', 'max_capacity']);
        $crowd = Gym::crowdFor($foundGyms);

        $gyms = $foundGyms
            ->map(fn($g) => [
                'crowd'        => $crowd[$g->id] ?? null,
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

    /** Whether the employee's name may appear in the "who is in the gym" list */
    public function gymPrivacy(): JsonResponse
    {
        return response()->json(['show_in_gym_list' => (bool) $this->getMyEmployee()->show_in_gym_list]);
    }

    public function updateGymPrivacy(Request $request): JsonResponse
    {
        $data = $request->validate(['show_in_gym_list' => 'required|boolean']);

        $employee = $this->getMyEmployee();
        $employee->update(['show_in_gym_list' => $data['show_in_gym_list']]);

        return response()->json([
            'show_in_gym_list' => (bool) $employee->show_in_gym_list,
            'message' => $employee->show_in_gym_list
                ? 'Your name can appear in the gym list.'
                : 'Your name is hidden from the gym list. You are still counted in the totals.',
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $employee = $this->getMyEmployee();

        $request->validate([
            'phone'      => 'nullable|string|max:20',
            'job_title'  => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'branch'     => 'nullable|string|max:100',
        ]);

        if ($request->has('phone')) {
            $employee->user?->update(['phone' => $request->phone]);
        }

        $employee->update($request->only(['job_title', 'department', 'branch']));

        return response()->json(['message' => 'Profile updated successfully.']);
    }
}
