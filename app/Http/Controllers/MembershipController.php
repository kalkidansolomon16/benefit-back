<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMembershipRequest;
use App\Http\Resources\MembershipResource;
use App\Models\Membership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MembershipController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $memberships = Membership::with(['employee.user', 'gym', 'plan'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->gym_id, fn($q) => $q->where('gym_id', $request->gym_id))
            ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
            ->latest()
            ->paginate(15);

        return MembershipResource::collection($memberships);
    }

    public function store(StoreMembershipRequest $request): JsonResponse
    {
        $membership = Membership::create($request->validated());
        // Increment gym current_members
        $membership->gym->increment('current_members');
        $membership->load(['employee', 'gym', 'plan']);
        return response()->json(new MembershipResource($membership), 201);
    }

    public function show(Membership $membership): MembershipResource
    {
        $membership->load(['employee.user', 'gym', 'plan', 'checkins' => fn($q) => $q->latest()->limit(20)]);
        return new MembershipResource($membership);
    }

    public function suspend(Request $request, Membership $membership): JsonResponse
    {
        $request->validate(['reason' => 'required|string|max:255']);
        $membership->update([
            'status'             => 'suspended',
            'suspension_reason'  => $request->reason,
            'suspended_at'       => now(),
        ]);
        return response()->json(['message' => 'Membership suspended.', 'membership' => new MembershipResource($membership)]);
    }

    public function reinstate(Membership $membership): JsonResponse
    {
        $membership->update(['status' => 'active', 'suspension_reason' => null, 'suspended_at' => null]);
        return response()->json(['message' => 'Membership reinstated.']);
    }

    public function destroy(Membership $membership): JsonResponse
    {
        $membership->gym->decrement('current_members');
        $membership->delete();
        return response()->json(['message' => 'Membership cancelled.']);
    }
}
