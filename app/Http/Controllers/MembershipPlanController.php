<?php

namespace App\Http\Controllers;

use App\Models\MembershipPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipPlanController extends Controller
{
    /**
     * List plans.
     * ?all=true  → admin view: all plans regardless of status
     * (default)  → public/employee view: only active plans
     */
    public function index(Request $request): JsonResponse
    {
        $query = MembershipPlan::query()->withCount('memberships');

        if (!$request->boolean('all')) {
            $query->where('is_active', true);
        }

        $plans = $query->orderBy('monthly_fee_etb')->get();

        return response()->json($plans);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:100',
            'tier'            => 'required|string|max:50|unique:membership_plans,tier',
            'monthly_fee_etb' => 'required|numeric|min:0',
            'duration_months' => 'nullable|integer|min:1',
            'features'        => 'nullable|array',
            'features.*'      => 'string|max:200',
            'target_level'    => 'nullable|in:chief,director,manager,staff,all',
            'is_active'       => 'boolean',
        ], [
            'tier.unique' => 'A plan with this tier key already exists.',
        ]);

        $plan = MembershipPlan::create($validated);

        return response()->json($plan->loadCount('memberships'), 201);
    }

    public function show(MembershipPlan $membershipPlan): JsonResponse
    {
        return response()->json($membershipPlan->loadCount('memberships'));
    }

    public function update(Request $request, MembershipPlan $membershipPlan): JsonResponse
    {
        $validated = $request->validate([
            'name'            => 'sometimes|required|string|max:100',
            'tier'            => 'sometimes|required|string|max:50|unique:membership_plans,tier,' . $membershipPlan->id,
            'monthly_fee_etb' => 'sometimes|required|numeric|min:0',
            'duration_months' => 'nullable|integer|min:1',
            'features'        => 'nullable|array',
            'features.*'      => 'string|max:200',
            'target_level'    => 'nullable|in:chief,director,manager,staff,all',
            'is_active'       => 'boolean',
        ], [
            'tier.unique' => 'A plan with this tier key already exists.',
        ]);

        $membershipPlan->update($validated);

        return response()->json($membershipPlan->loadCount('memberships'));
    }

    public function destroy(MembershipPlan $membershipPlan): JsonResponse
    {
        // Prevent deletion if active memberships are linked
        $activeMemberships = $membershipPlan->memberships()->whereIn('status', ['active', 'suspended'])->count();

        if ($activeMemberships > 0) {
            return response()->json([
                'message' => "Cannot delete — {$activeMemberships} active membership(s) use this plan. Deactivate it instead.",
            ], 422);
        }

        $membershipPlan->delete();

        return response()->json(['message' => 'Plan deleted.']);
    }

    public function toggleActive(MembershipPlan $membershipPlan): JsonResponse
    {
        $membershipPlan->update(['is_active' => !$membershipPlan->is_active]);

        return response()->json([
            'is_active' => $membershipPlan->is_active,
            'message'   => 'Plan ' . ($membershipPlan->is_active ? 'activated' : 'deactivated') . '.',
        ]);
    }
}
