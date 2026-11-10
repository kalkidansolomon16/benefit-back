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

        // ── Resolve the employee's package ──────────────────────────
        // HR's package choice is saved as the employee level (chief = Premium …);
        // App\Support\Tier is the single rule shared with check-in and billing.
        $canonicalTier  = \App\Support\Tier::forLevel($employee->level ?? 'staff');
        $allActivePlans = MembershipPlan::where('is_active', true)->orderBy('monthly_fee_etb')->get();

        $myPlan = \App\Services\MembershipService::resolvePlan($canonicalTier);

        $planKey   = $canonicalTier;
        $planName  = $myPlan?->name ?? ucwords(str_replace('_', ' ', $canonicalTier));
        $planFee   = (float) ($myPlan?->monthly_fee_etb ?? 0);
        $myGymTier = $canonicalTier;

        // Higher packages include the lower ones' gyms (Premium → all gyms)
        $accessibleTiers = \App\Support\Tier::gymTiersFor($canonicalTier);

        // Plan name for each gym tier, for the tier pills and the filter
        // (keyed by canonical tier: basic / basic_plus / premium, each once)
        $displayTiers = array_values(array_unique(array_map([\App\Support\Tier::class, 'canonical'], $accessibleTiers)));
        $accessiblePlanLabels = [];
        foreach ($displayTiers as $t) {
            $label = $allActivePlans->first(fn($p) => \App\Support\Tier::canonical($p->tier) === $t)?->name;
            $accessiblePlanLabels[$t] = $label ?? ucwords(str_replace('_', ' ', $t));
        }

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
            ->get(['id', 'name', 'tier', 'sub_city', 'city', 'address', 'latitude', 'longitude', 'facilities', 'opening_hours', 'max_capacity']);
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
                'latitude'     => $g->latitude !== null ? (float) $g->latitude : null,
                'longitude'    => $g->longitude !== null ? (float) $g->longitude : null,
                'facilities'   => is_array($g->facilities) ? $g->facilities : [],
                'opening_hours'=> $g->opening_hours,
            ]);

        return response()->json([
            'profile' => [
                'name'       => $user?->name,
                'email'      => $user?->email,
                'phone'      => $user?->phone,
                'fan_number' => $employee->fan_number,
                'card_number' => $employee->card_number,
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
                'status'          => $employee->accessStatus(),
                'valid_until'     => $employee->paid_until?->toDateString(),
                'access_message'  => $employee->hasPaidAccess() ? null : $employee->paidAccessMessage(),
                'accessible_tiers'=> $displayTiers,
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
