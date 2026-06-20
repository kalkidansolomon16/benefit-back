<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMembershipRequest;
use App\Http\Resources\MembershipResource;
use App\Models\AuditLog;
use App\Models\Membership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MembershipController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $memberships = Membership::with(['employee.user', 'gym', 'plan'])
            ->when($request->status,      fn($q) => $q->where('status', $request->status))
            ->when($request->gym_id,      fn($q) => $q->where('gym_id', $request->gym_id))
            ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->plan_id,     fn($q) => $q->where('plan_id', $request->plan_id))
            ->when($request->gym_tier,    fn($q) => $q->whereHas('gym', fn($g) => $g->where('tier', $request->gym_tier)))
            ->when($request->company_id,  fn($q) => $q->whereHas('employee', fn($e) => $e->where('company_id', $request->company_id)))
            ->when($request->search,      fn($q) => $q->whereHas('employee.user', fn($u) =>
                $u->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%")
            ))
            ->latest()
            ->paginate(10);

        return MembershipResource::collection($memberships);
    }

    public function store(StoreMembershipRequest $request): JsonResponse
    {
        $membership = Membership::create($request->validated());
        // Increment gym current_members
        $membership->gym->increment('current_members');
        $membership->load(['employee', 'gym', 'plan']);
        AuditLog::record('created', $membership);
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
        AuditLog::record('updated', $membership, ['status' => 'active'], ['status' => 'suspended']);
        return response()->json(['message' => 'Membership suspended.', 'membership' => new MembershipResource($membership)]);
    }

    public function reinstate(Membership $membership): JsonResponse
    {
        $membership->update(['status' => 'active', 'suspension_reason' => null, 'suspended_at' => null]);
        AuditLog::record('updated', $membership, ['status' => 'suspended'], ['status' => 'active']);
        return response()->json(['message' => 'Membership reinstated.']);
    }

    public function destroy(Membership $membership): JsonResponse
    {
        $membership->gym->decrement('current_members');
        AuditLog::record('deleted', $membership);
        $membership->delete();
        return response()->json(['message' => 'Membership cancelled.']);
    }
}
