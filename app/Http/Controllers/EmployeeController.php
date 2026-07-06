<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\TelegramService;
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
            ->paginate(min((int) ($request->per_page ?? 15), 500));

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

    /** ── Admin: activate or deactivate an employee's user account ── */
    public function toggleActive(Employee $employee): JsonResponse
    {
        $user = $employee->user;
        if (!$user) {
            return response()->json(['message' => 'No user account linked to this employee.'], 422);
        }

        $newState = !$user->is_active;
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
        $request->validate([
            'payment_status' => 'required|in:paid,unpaid',
        ]);

        $employee->update([
            'admin_approval_status' => 'approved',
            'payment_status'        => $request->payment_status,
            'is_enrolled'           => true,
            'enrolled_at'           => $employee->enrolled_at ?? now(),
        ]);

        // Activate the user account now that both approvals are done
        $employee->user?->update(['is_active' => true]);

        AuditLog::record('updated', $employee, ['admin_approval_status' => 'pending'], [
            'admin_approval_status' => 'approved',
            'payment_status'        => $request->payment_status,
        ]);

        // Email first — most critical, must not be blocked by Telegram
        if ($employee->user?->email) {
            try {
                \Illuminate\Support\Facades\Log::info('[FitAccess] Sending approval email to: ' . $employee->user->email . ' | mailer: ' . config('mail.default') . ' | host: ' . config('mail.mailers.smtp.host'));
                \Illuminate\Support\Facades\Mail::to($employee->user->email)
                    ->send(new \App\Mail\AccountApprovedMail($employee->user));
                \Illuminate\Support\Facades\Log::info('[FitAccess] Approval email sent OK to: ' . $employee->user->email);
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
}
