<?php

namespace App\Http\Controllers;

use App\Models\MembershipPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipPlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(MembershipPlan::where('is_active', true)->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:100',
            'tier'            => 'required|in:platinum,basic_plus,basic',
            'monthly_fee_etb' => 'required|numeric|min:0',
            'duration_months' => 'nullable|integer|min:1',
            'features'        => 'nullable|array',
            'target_level'    => 'nullable|in:chief,director,manager,staff,all',
        ]);
        $plan = MembershipPlan::create($validated);
        return response()->json($plan, 201);
    }

    public function show(MembershipPlan $membershipPlan): JsonResponse
    {
        return response()->json($membershipPlan->loadCount('memberships'));
    }

    public function update(Request $request, MembershipPlan $membershipPlan): JsonResponse
    {
        $membershipPlan->update($request->all());
        return response()->json($membershipPlan);
    }

    public function destroy(MembershipPlan $membershipPlan): JsonResponse
    {
        $membershipPlan->delete();
        return response()->json(['message' => 'Plan deleted.']);
    }
}
