<?php

namespace App\Http\Controllers;

use App\Models\AdminNotification;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeImportService;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class HRController extends Controller
{
    /* ── Helpers ─────────────────────────────────────────────────── */

    private function getMyCompany(): Company
    {
        $user = auth()->user();

        if ($user->company_id) {
            $company = Company::find($user->company_id);
            if ($company) return $company;
        }

        $company = Company::where('contact_email', $user->email)->first();

        if (!$company) {
            abort(403, 'No company is associated with this account.');
        }

        return $company;
    }

    /** Branch this login belongs to; null for the head office (main company login) */
    private function myBranchId(): ?int
    {
        return auth()->user()->company_branch_id ?: null;
    }

    /** Head office can manage every employee; a branch login only its own branch's */
    private function canManage(Employee $employee): bool
    {
        $mine = $this->myBranchId();
        return $mine === null || (int) $employee->company_branch_id === $mine;
    }

    private function assertCanManage(Employee $employee): void
    {
        if (!$this->canManage($employee)) {
            $owner = $employee->companyBranch?->name
                ? "the {$employee->companyBranch->name} branch"
                : 'the head office';
            abort(403, "You can't approve, reject or change this employee: they belong to {$owner}. "
                . 'Your branch account can only manage its own employees and view the others read-only.');
        }
    }

    /**
     * Narrow an employee query to one branch.
     * ?branch_id=<id> picks a branch, ?branch_id=main the head office.
     * With no param a branch login defaults to its own branch when $defaultOwn is set.
     */
    private function branchScope($query, Request $request, Company $company, bool $defaultOwn)
    {
        $raw = $request->query('branch_id');

        if ($raw === 'all') {
            return $query;
        }
        if ($raw === 'main') {
            return $query->whereNull('company_branch_id');
        }
        if ($raw !== null && ctype_digit((string) $raw) && $company->branches()->whereKey((int) $raw)->exists()) {
            return $query->where('company_branch_id', (int) $raw);
        }
        if ($raw === null && $defaultOwn && $this->myBranchId()) {
            return $query->where('company_branch_id', $this->myBranchId());
        }

        return $query;
    }

    /* ── Company info ────────────────────────────────────────────── */

    public function myCompany(): JsonResponse
    {
        return response()->json($this->getMyCompany());
    }

    /* ── HR Dashboard ────────────────────────────────────────────── */

    public function dashboard(Request $request): JsonResponse
    {
        $company   = $this->getMyCompany();
        $employees = $this->branchScope($company->employees(), $request, $company, true)
            ->with(['user:id,name,fan_number', 'activeMembership'])
            ->latest()
            ->get();

        $totalEmployees   = $employees->count();
        $activeMembers    = $employees->where('is_enrolled', true)->count();
        $suspendedMembers = $employees->filter(
            fn($e) => $e->activeMembership?->status === 'suspended'
        )->count();

        $distribution = [
            'basic'      => $employees->filter(fn($e) => in_array($e->level, ['staff', 'manager', null]))->count(),
            'basic_plus' => $employees->filter(fn($e) => $e->level === 'director')->count(),
            'platinum'   => $employees->filter(fn($e) => $e->level === 'chief')->count(),
        ];

        $recent = $employees
            ->sortByDesc('enrolled_at')
            ->take(10)
            ->map(fn($e) => [
                'id'          => $e->id,
                'name'        => $e->user?->name,
                'fan_number'  => $e->fan_number,
                'package'     => Employee::levelToPackage($e->level ?? 'staff'),
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

        $employees = $this->branchScope($company->employees(), $request, $company, false)
            ->with(['user:id,name,email,phone', 'activeMembership', 'companyBranch:id,name'])
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
                'package'             => Employee::levelToPackage($e->level ?? 'staff'),
                'level'               => $e->level,
                'job_title'           => $e->job_title,
                'department'          => $e->department,
                'branch'              => $e->companyBranch?->name ?? $e->branch,
                'company_branch_id'   => $e->company_branch_id,
                'can_manage'          => $this->canManage($e),
                'request_note'        => $e->request_note,
                'registration_status' => $e->registration_status ?? 'approved',
                'admin_approval_status' => $e->admin_approval_status,
                'payment_preference'  => $e->payment_preference,
                'status'              => $e->activeMembership?->status ?? ($e->is_enrolled ? 'active' : 'inactive'),
                'enrolled_at'         => $e->enrolled_at?->toDateString() ?? $e->created_at->toDateString(),
                'is_banned'           => $e->is_banned,
                'banned_until'        => $e->banned_until?->toIso8601String(),
                'ban_reason'          => $e->ban_reason,
            ]);

        // A branch login only has to act on its own branch's pending employees
        $allEmployees = $company->employees()
            ->when($this->myBranchId(), fn($q, $b) => $q->where('company_branch_id', $b))
            ->get();
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
        $this->assertCanManage($employee);

        $request->validate([
            'plan'               => 'nullable|string|max:50',
            'payment_preference' => 'nullable|in:pay_now,pay_later',
        ]);

        $tier  = $request->plan ?? 'basic';
        $level = Employee::packageToLevel($tier);

        $employee->update([
            'registration_status'   => 'approved',
            'admin_approval_status' => 'pending',
            'is_enrolled'           => true,
            'enrolled_at'           => now(),
            'level'                 => $level,
            'payment_status'        => 'unpaid',
            'payment_preference'    => $request->payment_preference,
        ]);

        if ($request->payment_preference === 'pay_now') {
            try {
                AdminNotification::invoiceRequest($company->name, $employee->user->name, $employee->id, $company->id);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Admin invoice-request notification failed: ' . $e->getMessage());
            }
        }

        // User stays INACTIVE — admin must give final approval before they can log in

        AuditLog::record('updated', $employee, ['registration_status' => 'pending'], [
            'registration_status'   => 'approved',
            'admin_approval_status' => 'pending',
        ]);

        if ($employee->user?->email) {
            try {
                \Illuminate\Support\Facades\Mail::to($employee->user->email)->send(
                    new \App\Mail\FitAccessNotificationMail(
                        recipientName: $employee->user->name,
                        emailSubject:  'Your FitAccess Registration Has Been Approved by HR',
                        heading:       'HR Approval Confirmed',
                        message:       "Your employee registration with " . ($employee->company?->name ?? 'your company') . " has been approved by HR.\n\nYour account is now pending final admin review. You will receive another email once fully activated — this usually takes 1–2 business days.",
                        buttonText:    'Check Your Status',
                        color:         '#f59e0b',
                    )
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('HR approve email failed: ' . $e->getMessage());
            }
        }

        try {
            app(TelegramService::class)->notifyUserApproved($employee->user, 'hr_approved');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('HR approve Telegram failed: ' . $e->getMessage());
        }

        return response()->json([
            'message'     => 'Employee approved by HR. Pending admin final approval before account is activated.',
            'employee_id' => $employee->id,
        ]);
    }

    /* ── Bulk approve / reject (company HR) ───────────────────────── */

    public function bulkApproveEmployees(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'                => 'required|array|min:1|max:200',
            'ids.*'              => 'integer',
            'plan'               => 'nullable|string|max:50',
            'payment_preference' => 'required|in:pay_now,pay_later',
        ]);

        return $this->runBulk($data['ids'], function (Employee $e) use ($data) {
            $this->approveEmployee(new Request([
                'plan'               => $data['plan'] ?? 'basic',
                'payment_preference' => $data['payment_preference'],
            ]), $e);
        }, 'approved');
    }

    public function bulkRejectEmployees(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'    => 'required|array|min:1|max:200',
            'ids.*'  => 'integer',
            'reason' => 'nullable|string|max:500',
        ]);

        return $this->runBulk($data['ids'], function (Employee $e) {
            $this->rejectEmployee($e);
        }, 'rejected');
    }

    /**
     * Runs $action for each of the company's employees that are still
     * pending HR review, and reports how many succeeded / were skipped.
     */
    private function runBulk(array $ids, \Closure $action, string $verb): JsonResponse
    {
        $company = $this->getMyCompany();

        $employees = Employee::with('user', 'company')
            ->where('company_id', $company->id)
            ->whereIn('id', $ids)
            ->get();

        $done = 0;
        $failed = [];
        foreach ($employees as $employee) {
            if (!$this->canManage($employee)) {
                $failed[] = ['id' => $employee->id, 'name' => $employee->user?->name, 'reason' => 'Belongs to another branch'];
                continue;
            }
            if ($employee->registration_status !== 'pending') {
                $failed[] = ['id' => $employee->id, 'name' => $employee->user?->name, 'reason' => 'Already processed'];
                continue;
            }
            try {
                $action($employee);
                $done++;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("HR bulk {$verb} failed for employee {$employee->id}: " . $e->getMessage());
                $failed[] = ['id' => $employee->id, 'name' => $employee->user?->name, 'reason' => 'Could not be processed'];
            }
        }

        $missing = count($ids) - $employees->count();
        if ($missing > 0) {
            $failed[] = ['id' => null, 'name' => null, 'reason' => "{$missing} not found in your company"];
        }

        return response()->json([
            'message' => "{$done} employee(s) {$verb}." . ($failed ? ' ' . count($failed) . ' skipped.' : ''),
            'done'    => $done,
            'failed'  => $failed,
        ]);
    }

    public function rejectEmployee(Employee $employee): JsonResponse
    {
        $company = $this->getMyCompany();
        if ($employee->company_id !== $company->id) abort(403);
        $this->assertCanManage($employee);

        $employee->update(['registration_status' => 'rejected']);
        $employee->user?->update(['is_active' => false]);

        AuditLog::record('updated', $employee, ['registration_status' => 'pending'], ['registration_status' => 'rejected']);

        if ($employee->user?->email) {
            try {
                \Illuminate\Support\Facades\Mail::to($employee->user->email)->send(
                    new \App\Mail\FitAccessNotificationMail(
                        recipientName: $employee->user->name,
                        emailSubject:  'Your FitAccess Registration Has Been Rejected',
                        heading:       'Registration Not Approved',
                        message:       "We're sorry to inform you that your employee registration with " . ($employee->company?->name ?? 'your company') . " was not approved by HR.\n\nPlease contact your HR team for more information.",
                        buttonText:    'Contact Support',
                        color:         '#ef4444',
                    )
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('HR reject email failed: ' . $e->getMessage());
            }
        }

        try {
            app(TelegramService::class)->notifyUserRejected($employee->user, 'employee');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('HR reject Telegram failed: ' . $e->getMessage());
        }

        return response()->json(['message' => 'Employee registration rejected.', 'employee_id' => $employee->id]);
    }

    /* ── Ban / Unban employee ───────────────────────────────────── */

    public function banEmployee(Request $request, Employee $employee): JsonResponse
    {
        $company = $this->getMyCompany();
        if ($employee->company_id !== $company->id) abort(403);
        $this->assertCanManage($employee);

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

        if ($employee->user) {
            app(TelegramService::class)->notifyUserBanned(
                $employee->user,
                $bannedUntil->toDateString(),
                $data['ban_reason'] ?? ''
            );
        }

        return response()->json([
            'message'  => "Employee banned until {$bannedUntil->toDateString()}.",
            'employee' => $this->employeePayload($employee->fresh()),
        ]);
    }

    public function unbanEmployee(Employee $employee): JsonResponse
    {
        $company = $this->getMyCompany();
        if ($employee->company_id !== $company->id) abort(403);
        $this->assertCanManage($employee);

        $employee->update(['banned_until' => null, 'ban_reason' => null]);
        AuditLog::record('updated', $employee, [], ['banned_until' => null]);

        return response()->json([
            'message'  => 'Employee ban has been lifted.',
            'employee' => $this->employeePayload($employee->fresh()),
        ]);
    }

    /** HR resets the authenticator of one of their own employees (lost phone) */
    public function resetEmployeeTwoFactor(Employee $employee): JsonResponse
    {
        $company = $this->getMyCompany();
        if ($employee->company_id !== $company->id) abort(403);
        $this->assertCanManage($employee);

        $user = $employee->user;
        if (!$user) {
            return response()->json(['message' => 'This employee has no login account.'], 404);
        }

        $user->resetTwoFactor();
        AuditLog::record('updated', $user, ['google2fa_enabled' => true], ['google2fa_enabled' => false]);

        return response()->json([
            'message' => "Two-factor authentication reset for {$user->name}. They will scan a new QR code on their next login.",
        ]);
    }

    private function employeePayload(Employee $e): array
    {
        $e->loadMissing(['user:id,name,email,phone', 'activeMembership', 'companyBranch:id,name']);
        return [
            'id'                  => $e->id,
            'name'                => $e->user?->name,
            'email'               => $e->user?->email,
            'phone'               => $e->user?->phone,
            'fan_number'          => $e->fan_number,
            'package'             => Employee::levelToPackage($e->level ?? 'staff'),
            'level'               => $e->level,
            'job_title'           => $e->job_title,
            'department'          => $e->department,
            'branch'              => $e->companyBranch?->name ?? $e->branch,
            'company_branch_id'   => $e->company_branch_id,
            'can_manage'          => $this->canManage($e),
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
            'first_name'    => 'required|string|max:100',
            'middle_name'   => 'required|string|max:100',
            'last_name'     => 'required|string|max:100',
            'fan_number'    => 'required|string|min:5|max:20|unique:employees,fan_number|unique:users,fan_number',
            'email'         => 'required|email|max:255|unique:users,email',
            'temp_password' => ['required', 'string', 'max:100', new \App\Rules\StrongPassword()],
            'package'       => ['required', 'string', Rule::exists('membership_plans', 'tier')->where(fn ($q) => $q->where('is_active', true))],
            'job_title'     => 'nullable|string|max:255',
            'department'    => 'nullable|string|max:255',
            'phone'         => 'nullable|string|max:20',
            'joined_at'     => 'nullable|date',
            'company_branch_id' => ['nullable', 'integer', Rule::exists('company_branches', 'id')
                ->where(fn ($q) => $q->where('company_id', $company->id)->whereNull('deleted_at'))],
            'photo'         => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
        ], [
            'fan_number.unique' => 'This FAN number is already registered.',
            'email.unique'      => 'This email address is already registered.',
            'package.exists'    => 'The selected package is no longer available.',
        ]);

        $level    = Employee::packageToLevel($request->package);
        $fullName = trim("{$request->first_name} {$request->middle_name} {$request->last_name}");

        // A branch login always registers into its own branch; head office may pick one
        $branchId = $this->myBranchId() ?? ($request->company_branch_id ?: null);
        $branch   = $branchId ? \App\Models\CompanyBranch::find($branchId) : null;

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('employee-photos', 'public');
        }

        return DB::transaction(function () use ($request, $company, $level, $fullName, $photoPath, $branch): JsonResponse {
            $user = User::create([
                'name'                 => $fullName,
                'email'                => $request->email,
                'password'             => Hash::make($request->temp_password),
                'role'                 => 'employee',
                'phone'                => $request->phone,
                'fan_number'           => $request->fan_number,
                'is_active'            => false,   // activated only after admin approves
                'must_reset_password'  => true,
            ]);

            $employee = Employee::create([
                'user_id'               => $user->id,
                'company_id'            => $company->id,
                'company_branch_id'     => $branch?->id,
                'branch'                => $branch?->name,
                'fan_number'            => $request->fan_number,
                'job_title'             => $request->job_title,
                'level'                 => $level,
                'department'            => $request->department,
                'photo_path'            => $photoPath,
                'registration_status'   => 'approved',   // HR-registered = already HR-approved
                'admin_approval_status' => 'pending',    // still needs admin sign-off
                'is_enrolled'           => true,
                'enrolled_at'           => $request->joined_at ?? now(),
                'payment_status'        => 'unpaid',
            ]);

            $employee->load('user');
            AuditLog::record('created', $employee);

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

    /* ── Bulk import employees (CSV) ─────────────────────────────── */

    public function importEmployees(Request $request, EmployeeImportService $importer): JsonResponse
    {
        $company = $this->getMyCompany();

        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        // A branch login imports into its own branch
        $branch = $this->myBranchId() ? \App\Models\CompanyBranch::find($this->myBranchId()) : null;

        $result = $importer->import($request->file('file'), $company, $branch);

        return response()->json([
            'message' => count($result['created']) . ' employee(s) imported, ' . count($result['failed']) . ' row(s) failed.',
            'created' => $result['created'],
            'failed'  => $result['failed'],
        ]);
    }
}
