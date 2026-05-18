<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Resources\EmployeeResource;
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
            ->when($request->search, fn($q) => $q->whereHas('user', fn($u) => $u->where('name', 'like', "%{$request->search}%")))
            ->latest()
            ->paginate(15);

        return EmployeeResource::collection($employees);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = Employee::create($request->validated());
        $employee->load(['user', 'company']);
        return response()->json(new EmployeeResource($employee), 201);
    }

    public function show(Employee $employee): EmployeeResource
    {
        $employee->load(['user', 'company', 'activeMembership.gym', 'activeMembership.plan', 'checkins' => fn($q) => $q->latest()->limit(10)]);
        return new EmployeeResource($employee);
    }

    public function update(StoreEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $employee->update($request->validated());
        return new EmployeeResource($employee->fresh(['user', 'company']));
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $employee->delete();
        return response()->json(['message' => 'Employee deleted.']);
    }

    public function enroll(Employee $employee): JsonResponse
    {
        $employee->update(['is_enrolled' => true, 'enrolled_at' => now()]);
        return response()->json(['message' => 'Employee enrolled successfully.', 'employee' => new EmployeeResource($employee)]);
    }
}
