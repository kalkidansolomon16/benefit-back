<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class HRController extends Controller
{
    /* ── Helpers ─────────────────────────────────────────────────── */

    private function getMyCompany(): Company
    {
        $company = Company::where('contact_email', auth()->user()->email)->first();

        if (!$company) {
            abort(403, 'No company is associated with this HR account.');
        }

        return $company;
    }

    private function levelToPackage(string $level): string
    {
        return match ($level) {
            'chief'    => 'platinum',
            'director' => 'basic_plus',
            default    => 'basic',
        };
    }

    private function packageToLevel(string $package): string
    {
        return match ($package) {
            'platinum'   => 'chief',
            'basic_plus' => 'director',
            default      => 'staff',
        };
    }

    /* ── Company info ────────────────────────────────────────────── */

    public function myCompany(): JsonResponse
    {
        return response()->json($this->getMyCompany());
    }

    /* ── HR Dashboard ────────────────────────────────────────────── */

    public function dashboard(): JsonResponse
    {
        $company   = $this->getMyCompany();
        $employees = $company->employees()
            ->with(['user:id,name,fan_number', 'activeMembership'])
            ->latest()
            ->get();

        $totalEmployees   = $employees->count();
        $activeMembers    = $employees->where('is_enrolled', true)->count();
        $suspendedMembers = $employees->filter(
            fn($e) => $e->activeMembership?->status === 'suspended'
        )->count();

        // Package distribution by level
        $distribution = [
            'basic'      => $employees->filter(fn($e) => in_array($e->level, ['staff', 'manager', null]))->count(),
            'basic_plus' => $employees->filter(fn($e) => $e->level === 'director')->count(),
            'platinum'   => $employees->filter(fn($e) => $e->level === 'chief')->count(),
        ];

        // Recent registrations (last 10 enrolled)
        $recent = $employees
            ->sortByDesc('enrolled_at')
            ->take(10)
            ->map(fn($e) => [
                'id'          => $e->id,
                'name'        => $e->user?->name,
                'fan_number'  => $e->fan_number,
                'package'     => $this->levelToPackage($e->level ?? 'staff'),
                'status'      => $e->activeMembership?->status ?? ($e->is_enrolled ? 'active' : 'inactive'),
                'enrolled_at' => $e->enrolled_at?->toDateString() ?? $e->created_at->toDateString(),
            ])->values();

        return response()->json([
            'company' => [
                'id'   => $company->id,
                'name' => $company->name,
                'tier' => $company->tier,
            ],
            'stats' => [
                'total_employees'   => $totalEmployees,
                'active_members'    => $activeMembers,
                'suspended_members' => $suspendedMembers,
            ],
            'package_distribution' => $distribution,
            'recent_registrations' => $recent,
        ]);
    }

    /* ── Employees list ──────────────────────────────────────────── */

    public function employees(Request $request): JsonResponse
    {
        $company = $this->getMyCompany();

        $employees = $company->employees()
            ->with(['user:id,name,email,phone', 'activeMembership'])
            ->when($request->search, fn($q) => $q->where(function ($sub) use ($request) {
                $sub->whereHas('user', fn($u) => $u->where('name', 'like', "%{$request->search}%"))
                    ->orWhere('fan_number', 'like', "%{$request->search}%");
            }))
            ->when($request->status, fn($q) => match($request->status) {
                'pending'  => $q->where('registration_status', 'pending'),
                'approved' => $q->where('registration_status', 'approved'),
                'rejected' => $q->where('registration_status', 'rejected'),
                default    => $q,
            })
            ->latest()
            ->get()
            ->map(fn($e) => [
                'id'                  => $e->id,
                'name'                => $e->user?->name,
                'email'               => $e->user?->email,
                'phone'               => $e->user?->phone,
                'fan_number'          => $e->fan_number,
                'package'             => $this->levelToPackage($e->level ?? 'staff'),
                'level'               => $e->level,
                'job_title'           => $e->job_title,
                'department'          => $e->department,
                'branch'              => $e->branch,
                'request_note'        => $e->request_note,
                'registration_status' => $e->registration_status ?? 'approved',
                'status'              => $e->activeMembership?->status ?? ($e->is_enrolled ? 'active' : 'inactive'),
                'enrolled_at'         => $e->enrolled_at?->toDateString() ?? $e->created_at->toDateString(),
            ]);

        // Counts for tabs
        $allEmployees = $company->employees()->get();
        $pendingCount = $allEmployees->where('registration_status', 'pending')->count();

        return response()->json([
            'data'          => $employees->values(),
            'total'         => $employees->count(),
            'pending_count' => $pendingCount,
        ]);
    }

    /* ── Approve / Reject employee ───────────────────────────────── */

    public function approveEmployee(Request $request, Employee $employee): JsonResponse
    {
        $company = $this->getMyCompany();
        if ($employee->company_id !== $company->id) abort(403);

        $request->validate([
            'plan' => 'nullable|string|exists:membership_plans,tier',
        ]);

        $tier  = $request->plan ?? 'basic';
        $level = $this->packageToLevel($tier);

        $employee->update([
            'registration_status' => 'approved',
            'is_enrolled'         => true,
            'enrolled_at'         => now(),
            'level'               => $level,
        ]);

        // Activate the user account so they can log in
        $employee->user?->update(['is_active' => true]);

        return response()->json(['message' => 'Employee approved and activated.', 'employee_id' => $employee->id]);
    }

    public function rejectEmployee(Employee $employee): JsonResponse
    {
        $company = $this->getMyCompany();
        if ($employee->company_id !== $company->id) abort(403);

        $employee->update(['registration_status' => 'rejected']);
        $employee->user?->update(['is_active' => false]);

        return response()->json(['message' => 'Employee registration rejected.', 'employee_id' => $employee->id]);
    }

    /* ── Register employee ───────────────────────────────────────── */

    public function registerEmployee(Request $request): JsonResponse
    {
        $company = $this->getMyCompany();

        $request->validate([
            'first_name'  => 'required|string|max:100',
            'middle_name' => 'required|string|max:100',
            'last_name'   => 'required|string|max:100',
            'fan_number'  => 'required|string|size:13|unique:employees,fan_number|unique:users,fan_number',
            'package'     => 'required|in:basic,basic_plus,platinum',
            'job_title'   => 'nullable|string|max:255',
            'department'  => 'nullable|string|max:255',
            'phone'       => 'nullable|string|max:20',
            'joined_at'   => 'nullable|date',
            'photo'       => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
        ], [
            'fan_number.size'   => 'FAN number must be exactly 13 digits.',
            'fan_number.unique' => 'This FAN number is already registered.',
        ]);

        $level    = $this->packageToLevel($request->package);
        $fullName = trim("{$request->first_name} {$request->middle_name} {$request->last_name}");
        // Generate a unique email from the FAN number
        $email    = "{$request->fan_number}@fitaccess.et";

        if (User::where('email', $email)->exists()) {
            $email = strtolower(str_replace(' ', '.', $fullName)) . "@fitaccess.et";
        }

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('employee-photos', 'public');
        }

        return DB::transaction(function () use ($request, $company, $level, $fullName, $email, $photoPath): JsonResponse {
            $user = User::create([
                'name'       => $fullName,
                'email'      => $email,
                'password'   => Hash::make($request->fan_number), // default password = FAN number
                'role'       => 'employee',
                'phone'      => $request->phone,
                'fan_number' => $request->fan_number,
                'is_active'  => true,
            ]);

            $employee = Employee::create([
                'user_id'    => $user->id,
                'company_id' => $company->id,
                'fan_number' => $request->fan_number,
                'job_title'  => $request->job_title,
                'level'      => $level,
                'department' => $request->department,
                'photo_path' => $photoPath,
                'is_enrolled'=> true,
                'enrolled_at'=> $request->joined_at ?? now(),
            ]);

            $employee->load('user');

            return response()->json([
                'message' => "Employee {$fullName} registered successfully.",
                'employee' => [
                    'id'         => $employee->id,
                    'name'       => $fullName,
                    'fan_number' => $employee->fan_number,
                    'package'    => $request->package,
                ],
            ], 201);
        });
    }
}
