<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCheckinRequest;
use App\Http\Resources\CheckinResource;
use App\Models\Checkin;
use App\Models\Employee;
use App\Models\Membership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CheckinController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $checkins = Checkin::with(['employee.user', 'gym'])
            ->when($request->gym_id, fn($q) => $q->where('gym_id', $request->gym_id))
            ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->date, fn($q) => $q->whereDate('checked_in_at', $request->date))
            ->latest('checked_in_at')
            ->paginate(20);

        return CheckinResource::collection($checkins);
    }

    public function store(StoreCheckinRequest $request): JsonResponse
    {
        $employee = Employee::where('fan_number', $request->fan_number)->firstOrFail();

        $membership = Membership::where('employee_id', $employee->id)
            ->where('gym_id', $request->gym_id)
            ->where('status', 'active')
            ->where('end_date', '>=', now())
            ->firstOrFail();

        $checkin = Checkin::create([
            'membership_id' => $membership->id,
            'gym_id'        => $request->gym_id,
            'employee_id'   => $employee->id,
            'checked_in_at' => now(),
            'method'        => $request->method ?? 'fan_number',
            'recorded_by'   => $request->recorded_by,
        ]);

        $checkin->load(['employee.user', 'gym']);
        return response()->json(new CheckinResource($checkin), 201);
    }

    public function checkout(Checkin $checkin): JsonResponse
    {
        if ($checkin->checked_out_at) {
            return response()->json(['message' => 'Already checked out.'], 422);
        }
        $checkin->update(['checked_out_at' => now()]);
        return response()->json(new CheckinResource($checkin->load(['employee.user', 'gym'])));
    }

    public function show(Checkin $checkin): CheckinResource
    {
        return new CheckinResource($checkin->load(['employee.user', 'gym', 'membership.plan']));
    }
}
