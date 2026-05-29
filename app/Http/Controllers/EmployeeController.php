<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $employees = Employee::query()
            ->with(['user', 'company', 'activeMembership.gym'])
            ->when($request->company_id, fn($q) => $q->where('company_id', $request->company_id))
            ->when($request->level, fn($q) => $q->where('level', $request->level))
            ->when($request->is_enrolled !== null, fn($q) => $q->where('is_enrolled', $request->boolean('is_enrolled')))
            ->when($request->search, fn($q) => $q
                ->whereHas('user', fn($u) => $u
                    ->where('name',  'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%")
                    ->orWhere('phone', 'like', "%{$request->search}%")
                )
                ->orWhere('fan_number', 'like', "%{$request->search}%")
            )
            ->latest()
            ->paginate(15);

        return EmployeeResource::collection($employees);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = Employee::create($request->validated());
        $employee->load(['user', 'company']);
        AuditLog::record('created', $employee);
        return response()->json(new EmployeeResource($employee), 201);
    }

    public function show(Employee $employee): EmployeeResource
    {
        $employee->load(['user', 'company', 'activeMembership.gym', 'activeMembership.plan', 'checkins' => fn($q) => $q->latest()->limit(10)]);
        return new EmployeeResource($employee);
    }

    public function update(StoreEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $old = $employee->only(array_keys($request->validated()));
        $employee->update($request->validated());
        AuditLog::record('updated', $employee, $old, $request->validated());
        return new EmployeeResource($employee->fresh(['user', 'company']));
    }

    public function destroy(Employee $employee): JsonResponse
    {
        AuditLog::record('deleted', $employee);
        $employee->delete();
        return response()->json(['message' => 'Employee deleted.']);
    }

    public function enroll(Employee $employee): JsonResponse
    {
        $employee->update(['is_enrolled' => true, 'enrolled_at' => now()]);
        AuditLog::record('updated', $employee, ['is_enrolled' => false], ['is_enrolled' => true]);
        return response()->json(['message' => 'Employee enrolled successfully.', 'employee' => new EmployeeResource($employee)]);
    }

    public function ban(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'days'       => 'required|integer|min:1|max:365',
            'ban_reason' => 'nullable|string|max:500',
        ]);

        $bannedUntil = now()->addDays($data['days']);
        $employee->update([
            'banned_until' => $bannedUntil,
            'ban_reason'   => $data['ban_reason'] ?? null,
        ]);

        AuditLog::record('updated', $employee, ['banned_until' => null], ['banned_until' => $bannedUntil]);

        return response()->json([
            'message'  => "Employee banned until {$bannedUntil->toDateString()}.",
            'employee' => new EmployeeResource($employee->fresh()),
        ]);
    }

    public function unban(Employee $employee): JsonResponse
    {
        $employee->update(['banned_until' => null, 'ban_reason' => null]);
        AuditLog::record('updated', $employee, [], ['banned_until' => null]);

        return response()->json([
            'message'  => 'Employee ban has been lifted.',
            'employee' => new EmployeeResource($employee->fresh()),
        ]);
    }
}
