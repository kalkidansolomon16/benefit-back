<?php

namespace App\Http\Controllers;

use App\Models\AdminNotification;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\TelegramService;
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
        $user = auth()->user();

        // Sub-users (company_finance, company_ceo) are linked directly via company_id
        if ($user->company_id) {
            $company = Company::find($user->company_id);
            if ($company) return $company;
        }

        // Primary HR user: match by contact_email
        $company = Company::where('contact_email', $user->email)->first();

        if (!$company) {
            abort(403, 'No company is associated with this account.');
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
        // Normalise: strip common tier prefixes (fit_, fir_, access_, etc.)
        // so 'fit_basic_plus' → 'basic_plus', 'fit_premium' → 'premium', etc.
        $norm = strtolower(preg_replace('/^[a-z]+_(?=basic|premium|platinum|gold|silver)/i', '', $package) ?? $package);

        return match (true) {
            in_array($norm, ['platinum', 'premium', 'gold'])  => 'chief',
            str_contains($norm, 'basic_plus')                 => 'director',
            str_contains($norm, 'manager')                    => 'manager',
            default                                           => 'staff',
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
                $sub->whereHas('user', fn($u) => $u
                    ->where('name',  'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%")
                    ->orWhere('phone', 'like', "%{$request->search}%")
                )
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
                'is_banned'           => $e->is_banned,
                'banned_until'        => $e->banned_until?->toIso8601String(),
                'ban_reason'          => $e->ban_reason,
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
            'plan'               => 'nullable|string|max:50',
            'payment_preference' => 'required|in:pay_now,pay_later',
        ]);

        $tier  = $request->plan ?? 'basic';
        $level = $this->packageToLevel($tier);

        $employee->update([
            'registration_status'   => 'approved',
            'admin_approval_status' => 'pending',
            'payment_preference'    => $request->payment_preference,
            'is_enrolled'         => true,
            'enrolled_at'         => now(),
            'level'               => $level,
            'payment_status'      => 'unpaid',   // memberships granted only after invoice is paid
        ]);

        // If company chose Pay Now, create an admin notification to generate an invoice
        if ($request->payment_preference === 'pay_now') {
            AdminNotification::invoiceRequest(
                $company->name,
                $employee->user?->name ?? 'Unknown',
                $employee->id,
                $company->id
            );
        }

        // User stays INACTIVE until admin gives final approval after payment review.
        // NOTE: memberships are NOT created here.
        // They will be auto-provisioned when the company's billing invoice is
        // verified/paid by the admin (see AdminBillingController::verifyPayment).

        AuditLog::record('updated', $employee, ['registration_status' => 'pending'], [
            'registration_status'   => 'approved',
            'payment_preference'    => $request->payment_preference,
            'admin_approval_status' => 'pending',
        ]);

        // Telegram notification to employee
        app(TelegramService::class)->notifyUserApproved($employee->user, 'hr_approved');

        return response()->json([
            'message'            => 'Employee approved by HR. Pending admin final approval.',
            'employee_id'        => $employee->id,
            'payment_preference' => $request->payment_preference,
        ]);
    }

    public function rejectEmployee(Employee $employee): JsonResponse
    {
        $company = $this->getMyCompany();
        if ($employee->company_id !== $company->id) abort(403);

        $employee->update(['registration_status' => 'rejected']);
        $employee->user?->update(['is_active' => false]);

        AuditLog::record('updated', $employee, ['registration_status' => 'pending'], ['registration_status' => 'rejected']);

        // Telegram notification to employee
        app(TelegramService::class)->notifyUserRejected($employee->user, 'employee');

        return response()->json(['message' => 'Employee registration rejected.', 'employee_id' => $employee->id]);
    }

    /* ── Ban / Unban employee ───────────────────────────────────── */

    public function banEmployee(Request $request, Employee $employee): JsonResponse
    {
        $company = $this->getMyCompany();
        if ($employee->company_id !== $company->id) abort(403);

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
            'employee' => $this->employeePayload($employee->fresh()),
        ]);
    }

    public function unbanEmployee(Employee $employee): JsonResponse
    {
        $company = $this->getMyCompany();
        if ($employee->company_id !== $company->id) abort(403);

        $employee->update(['banned_until' => null, 'ban_reason' => null]);
        AuditLog::record('updated', $employee, [], ['banned_until' => null]);

        return response()->json([
            'message'  => 'Employee ban has been lifted.',
            'employee' => $this->employeePayload($employee->fresh()),
        ]);
    }

    private function employeePayload(Employee $e): array
    {
        $e->loadMissing(['user:id,name,email,phone', 'activeMembership']);
        return [
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
            'is_banned'           => $e->is_banned,
            'banned_until'        => $e->banned_until?->toIso8601String(),
            'ban_reason'          => $e->ban_reason,
        ];
    }

    /* ── Register employee ───────────────────────────────────────── */

    public function registerEmployee(Request $request): JsonResponse
    {
        $company = $this->getMyCompany();

        $request->validate([
            'first_name'         => 'required|string|max:100',
            'middle_name'        => 'required|string|max:100',
            'last_name'          => 'required|string|max:100',
            'fan_number'         => 'required|string|min:5|max:20|unique:employees,fan_number|unique:users,fan_number',
            'email'              => 'required|email|max:255|unique:users,email',
            'temp_password'      => 'required|string|min:6|max:100',
            'payment_preference' => 'required|in:pay_now,pay_later',
            'package'            => 'required|string|max:50',
            'job_title'          => 'nullable|string|max:255',
            'department'         => 'nullable|string|max:255',
            'phone'              => 'nullable|string|max:20',
            'joined_at'          => 'nullable|date',
            'photo'              => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
        ], [
            'fan_number.min'         => 'FAN number must be at least 5 digits.',
            'fan_number.max'         => 'FAN number must not exceed 20 digits.',
            'fan_number.unique'      => 'This FAN number is already registered.',
            'email.unique'           => 'This email address is already registered.',
            'temp_password.min'      => 'Password must be at least 6 characters.',
            'payment_preference.in'  => 'Please select Pay Now or Pay Later.',
        ]);

        $level    = $this->packageToLevel($request->package);
        $fullName = trim("{$request->first_name} {$request->middle_name} {$request->last_name}");

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('employee-photos', 'public');
        }

        return DB::transaction(function () use ($request, $company, $level, $fullName, $photoPath): JsonResponse {
            $user = User::create([
                'name'       => $fullName,
                'email'      => $request->email,
                'password'   => Hash::make($request->temp_password),
                'role'       => 'employee',
                'phone'      => $request->phone,
                'fan_number' => $request->fan_number,
                'is_active'  => false,   // activated after admin final approval
            ]);

            $employee = Employee::create([
                'user_id'               => $user->id,
                'company_id'            => $company->id,
                'fan_number'            => $request->fan_number,
                'job_title'             => $request->job_title,
                'level'                 => $level,
                'department'            => $request->department,
                'photo_path'            => $photoPath,
                'registration_status'   => 'approved',
                'admin_approval_status' => 'pending',
                'payment_preference'    => $request->payment_preference,
                'is_enrolled'           => true,
                'enrolled_at'           => $request->joined_at ?? now(),
                'payment_status'        => 'unpaid',
            ]);

            // If Pay Now, notify admin immediately to generate invoice
            if ($request->payment_preference === 'pay_now') {
                AdminNotification::invoiceRequest(
                    $company->name,
                    $fullName,
                    $employee->id,
                    $company->id
                );
            }

            $employee->load('user');
            AuditLog::record('created', $employee);

            return response()->json([
                'message' => "Employee {$fullName} registered successfully. " .
                    ($request->payment_preference === 'pay_now'
                        ? 'Admin has been notified to generate the invoice.'
                        : 'Account will be activated once the admin approves.'),
                'employee' => [
                    'id'                 => $employee->id,
                    'name'               => $fullName,
                    'fan_number'         => $employee->fan_number,
                    'package'            => $request->package,
                    'payment_preference' => $request->payment_preference,
                    'payment_status'     => 'unpaid',
                ],
            ], 201);
        });
    }
}
