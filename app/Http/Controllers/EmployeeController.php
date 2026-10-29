<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeImportService;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $employees = Employee::query()
            ->with(['user', 'company', 'activeMembership.gym', 'activeMembership.plan'])
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
            ->paginate(min((int) ($request->per_page ?? 15), 500));

        return EmployeeResource::collection($employees);
    }

    /** ── Admin-only: bulk-create employees from a CSV file ── */
    public function importEmployees(Request $request, EmployeeImportService $importer): JsonResponse
    {
        abort_unless(in_array($request->user()->role, User::ADMIN_ROLES), 403);

        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $result = $importer->import($request->file('file'));

        return response()->json([
            'message' => count($result['created']) . ' employee(s) imported, ' . count($result['failed']) . ' row(s) failed.',
            'created' => $result['created'],
            'failed'  => $result['failed'],
        ]);
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

    /** ── Admin: activate or deactivate an employee's user account ── */
    public function toggleActive(Employee $employee): JsonResponse
    {
        $user = $employee->user;
        if (!$user) {
            return response()->json(['message' => 'No user account linked to this employee.'], 422);
        }

        $newState = !$user->is_active;

        // Two-step approval: the company (HR) must approve before the account can be activated
        if ($newState && ($employee->registration_status ?? 'approved') !== 'approved') {
            return response()->json(['message' => 'This employee has not been approved by their company (HR) yet.'], 422);
        }

        // Activating an employee who is still awaiting admin approval counts as approving them,
        // so the same payment rule applies
        if ($newState && $employee->admin_approval_status === 'pending' && $employee->payment_status !== 'paid') {
            return response()->json(['message' => 'Payment has not been received for this employee yet. They can only be approved once their payment status is Paid.'], 422);
        }

        $user->update(['is_active' => $newState]);

        // Keep admin_approval_status in sync so the approval queue stays clean
        if ($newState && $employee->admin_approval_status === 'pending') {
            $employee->update(['admin_approval_status' => 'approved']);
        }

        AuditLog::record('updated', $employee, ['user_is_active' => !$newState], ['user_is_active' => $newState]);

        if ($newState && $user->email) {
            try {
                \Illuminate\Support\Facades\Mail::to($user->email)
                    ->send(new \App\Mail\AccountApprovedMail($user));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Employee activate email failed: ' . $e->getMessage());
            }
        }

        try {
            $telegram = app(TelegramService::class);
            if ($newState) {
                $telegram->notifyUserApproved($user, 'employee');
                $telegram->notifyHREmployeeApprovedByAdmin($employee->load('company'));
            } else {
                $telegram->notifyUserDeactivated($user);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Employee toggle-active Telegram failed: ' . $e->getMessage());
        }

        return response()->json([
            'message'        => $newState ? 'Employee account activated.' : 'Employee account deactivated.',
            'user_is_active' => $newState,
            'employee'       => new EmployeeResource($employee->load('user', 'company')),
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

    /** ── Admin-only: list employees pending admin final approval ── */
    public function pendingAdminApproval(Request $request): JsonResponse
    {
        $employees = Employee::with(['user', 'company'])
            ->where('admin_approval_status', 'pending')
            ->where('registration_status', 'approved')
            ->when($request->search, fn($q) => $q->whereHas('user', fn($u) => $u
                ->where('name', 'like', "%{$request->search}%")
                ->orWhere('email', 'like', "%{$request->search}%")
            ))
            ->latest()
            ->get()
            ->map(fn($e) => [
                'id'                   => $e->id,
                'name'                 => $e->user?->name,
                'email'                => $e->user?->email,
                'fan_number'           => $e->fan_number,
                'company'              => $e->company?->name,
                'company_id'           => $e->company_id,
                'level'                => $e->level,
                'payment_preference'   => $e->payment_preference,
                'payment_status'       => $e->payment_status,
                'admin_approval_status'=> $e->admin_approval_status,
                'enrolled_at'          => $e->enrolled_at?->toDateString(),
                'created_at'           => $e->created_at->toDateString(),
            ]);

        return response()->json([
            'data'  => $employees,
            'total' => $employees->count(),
        ]);
    }

    /** ── Admin-only: approve employee + set payment status ── */
    public function adminApprove(Request $request, Employee $employee): JsonResponse
    {
        if (($employee->registration_status ?? 'approved') !== 'approved') {
            return response()->json(['message' => 'This employee has not been approved by their company (HR) yet.'], 422);
        }

        // Payment must already be recorded (invoice paid) before the admin can approve
        if ($employee->payment_status !== 'paid') {
            return response()->json(['message' => 'Payment has not been received for this employee yet. They can only be approved once their payment status is Paid.'], 422);
        }

        $employee->update([
            'admin_approval_status' => 'approved',
            'is_enrolled'           => true,
            'enrolled_at'           => $employee->enrolled_at ?? now(),
        ]);

        // Activate the user account now that both approvals are done
        $employee->user?->update(['is_active' => true]);

        AuditLog::record('updated', $employee, ['admin_approval_status' => 'pending'], [
            'admin_approval_status' => 'approved',
        ]);

        // Email first — most critical, must not be blocked by Telegram
        if ($employee->user?->email) {
            try {
                \Illuminate\Support\Facades\Log::info('[FitAccess] Sending approval email to: ' . $this->maskEmail($employee->user->email) . ' | mailer: ' . config('mail.default') . ' | host: ' . config('mail.mailers.smtp.host'));
                \Illuminate\Support\Facades\Mail::to($employee->user->email)
                    ->send(new \App\Mail\AccountApprovedMail($employee->user));
                \Illuminate\Support\Facades\Log::info('[FitAccess] Approval email sent OK to: ' . $this->maskEmail($employee->user->email));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('[FitAccess] Employee approval email FAILED [' . get_class($e) . ']: ' . $e->getMessage());
            }
        }

        // Telegram separately — failure must never affect the email above
        try {
            $telegram = app(TelegramService::class);
            $telegram->notifyUserApproved($employee->user, 'employee');
            $telegram->notifyHREmployeeApprovedByAdmin($employee->load('company'));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Employee approval Telegram failed: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Employee approved by admin. Account is now active.',
            'employee_id' => $employee->id,
        ]);
    }

    /** ── Admin-only: reject employee at admin stage ── */
    public function adminReject(Request $request, Employee $employee): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $employee->update([
            'admin_approval_status' => 'rejected',
        ]);

        AuditLog::record('updated', $employee, ['admin_approval_status' => 'pending'], [
            'admin_approval_status' => 'rejected',
        ]);

        $reason = $request->reason ?? null;

        if ($employee->user?->email) {
            try {
                \Illuminate\Support\Facades\Mail::to($employee->user->email)->send(
                    new \App\Mail\FitAccessNotificationMail(
                        recipientName: $employee->user->name,
                        emailSubject:  'Your FitAccess Account Application Was Not Approved',
                        heading:       'Account Not Approved',
                        message:       "We regret to inform you that your FitAccess employee account was not approved at the admin review stage."
                                      . ($reason ? "\n\nReason: {$reason}" : '')
                                      . "\n\nPlease contact your HR team or FitAccess support for assistance.",
                        buttonText:    'Contact Support',
                        color:         '#ef4444',
                    )
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Employee rejection email failed: ' . $e->getMessage());
            }
        }

        try {
            $telegram = app(TelegramService::class);
            $telegram->notifyUserRejected($employee->user, 'employee', $reason);
            $telegram->notifyHREmployeeRejectedByAdmin($employee->load('company'), $reason);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Employee rejection Telegram failed: ' . $e->getMessage());
        }

        return response()->json(['message' => 'Employee rejected at admin stage.']);
    }

    /** ── Admin-only: approve many employees at once ── */
    public function bulkAdminApprove(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'            => 'required|array|min:1|max:200',
            'ids.*'          => 'integer',
        ]);

        return $this->runBulk($data['ids'], function (Employee $e) use ($request) {
            $response = $this->adminApprove($request, $e);
            if ($response->getStatusCode() >= 400) {
                throw new \RuntimeException($response->getData()->message ?? 'Could not be approved');
            }
        }, 'approved');
    }

    /** ── Admin-only: reject many employees at once ── */
    public function bulkAdminReject(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'    => 'required|array|min:1|max:200',
            'ids.*'  => 'integer',
            'reason' => 'required|string|max:500',
        ]);

        return $this->runBulk($data['ids'], function (Employee $e) use ($data) {
            $this->adminReject(new Request(['reason' => $data['reason']]), $e);
        }, 'rejected');
    }

    /** Applies $action to every id that is HR-approved and still awaiting admin review */
    private function runBulk(array $ids, \Closure $action, string $verb): JsonResponse
    {
        $employees = Employee::with('user', 'company')
            ->whereIn('id', $ids)
            ->get();

        $done = 0;
        $failed = [];
        foreach ($employees as $employee) {
            if ($employee->admin_approval_status !== 'pending' || $employee->registration_status !== 'approved') {
                $failed[] = ['id' => $employee->id, 'name' => $employee->user?->name, 'reason' => 'Not awaiting admin approval'];
                continue;
            }
            try {
                $action($employee);
                $done++;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Admin bulk {$verb} failed for employee {$employee->id}: " . $e->getMessage());
                $failed[] = [
                    'id'     => $employee->id,
                    'name'   => $employee->user?->name,
                    'reason' => $e instanceof \RuntimeException ? 'Payment not received' : 'Could not be processed',
                ];
            }
        }

        $missing = count($ids) - $employees->count();
        if ($missing > 0) {
            $failed[] = ['id' => null, 'name' => null, 'reason' => "{$missing} not found"];
        }

        return response()->json([
            'message' => "{$done} employee(s) {$verb}." . ($failed ? ' ' . count($failed) . ' skipped.' : ''),
            'done'    => $done,
            'failed'  => $failed,
        ]);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($local === '') {
            return $email;
        }
        $visible = mb_substr($local, 0, 1);
        return $visible . str_repeat('*', max(strlen($local) - 1, 1)) . '@' . $domain;
    }
}
