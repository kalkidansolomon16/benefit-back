<?php

namespace App\Http\Controllers;

use App\Models\BillingInvoice;
use App\Models\CompanyBranch;
use App\Models\Employee;
use App\Services\EmployeeRosterService;
use App\Services\OnboardingDocuments;
use App\Support\BillingCalendar;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Company side of onboarding: download the contract and Excel template,
 * import the employee list (preview first, then confirm), resend invitations,
 * and see the deadlines.
 */
class HROnboardingController extends HRController
{
    /** Deadlines, payment calendar and progress for the HR portal banner */
    public function status(): JsonResponse
    {
        $company = $this->getMyCompany();
        $employees = $company->employees()
            ->when($this->myBranchId(), fn ($q, $b) => $q->where('company_branch_id', $b))
            ->get(['id', 'account_created_at', 'invited_at', 'invite_expires_at', 'card_issued_at', 'card_activated_at', 'paid_until', 'registration_status']);

        // The month whose staff list is being collected now (cut-off on the 20th)
        $period = BillingCalendar::isAfterCutoff() ? BillingCalendar::upcomingPeriodStart()->addMonthNoOverflow() : BillingCalendar::upcomingPeriodStart();
        $openInvoice = BillingInvoice::where('company_id', $company->id)
            ->whereIn('status', ['sent', 'overdue', 'closed'])
            ->latest('id')->first(['id', 'invoice_number', 'status', 'due_date', 'closes_on', 'total_amount', 'late_fee_amount']);

        $deadline = $company->import_deadline;

        return response()->json([
            'contract_sent_at' => $company->contract_sent_at?->toIso8601String(),
            'import_deadline'  => $deadline?->toDateString(),
            'days_left'        => $deadline ? (int) now()->startOfDay()->diffInDays($deadline, false) : null,
            'next_period'      => [
                'start'             => $period->toDateString(),
                'label'             => $period->format('F Y'),
                'staff_list_deadline' => BillingCalendar::staffListDeadline($period)->toDateString(),
                'send_date'         => BillingCalendar::sendDate($period)->toDateString(),
                'due_date'          => BillingCalendar::dueDate($period)->toDateString(),
                'closes_on'         => BillingCalendar::closesOn($period)->toDateString(),
                'window'            => BillingCalendar::windowText($period),
            ],
            'rules'            => BillingCalendar::rulesText(),
            'late_fee_percent' => BillingCalendar::lateFeePercent(),
            'billing_cycle_months' => (int) ($company->billing_cycle_months ?: 1),
            'counts'           => [
                'employees'        => $employees->count(),
                'awaiting_account' => $employees->whereNull('account_created_at')->count(),
                'accounts_created' => $employees->whereNotNull('account_created_at')->count(),
                'cards_to_send'    => $employees->whereNotNull('card_issued_at')->whereNull('card_activated_at')->count(),
                'cards_active'     => $employees->whereNotNull('card_activated_at')->count(),
            ],
            'open_invoice'     => $openInvoice ? [
                'id' => $openInvoice->id, 'number' => $openInvoice->invoice_number, 'status' => $openInvoice->status,
                'due_date' => $openInvoice->due_date?->toDateString(), 'closes_on' => $openInvoice->closes_on?->toDateString(),
                'total' => (float) $openInvoice->total_amount, 'late_fee' => (float) $openInvoice->late_fee_amount,
            ] : null,
        ]);
    }

    public function template(): Response
    {
        $company = $this->getMyCompany();

        return response(OnboardingDocuments::employeeTemplate($company), 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . OnboardingDocuments::templateFilename($company) . '"',
        ]);
    }

    public function contract(): Response
    {
        $this->getMyCompany();
        $doc = OnboardingDocuments::contract();

        return response($doc['content'], 200, [
            'Content-Type'        => $doc['mime'],
            'Content-Disposition' => 'attachment; filename="' . $doc['name'] . '"',
        ]);
    }

    /** Step 1: read the file and show what would happen. Nothing is saved. */
    public function preview(Request $request, EmployeeRosterService $roster): JsonResponse
    {
        $company = $this->getMyCompany();
        $request->validate(['file' => 'required|file|mimes:xlsx,csv,txt|max:5120']);

        try {
            $rows = $roster->readFile($request->file('file'));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: 'The file could not be read.'], 422);
        }
        if (!$rows) {
            return response()->json(['message' => 'The file has no employee rows. Fill in the "Employees" sheet of the FitAccess template.'], 422);
        }
        if (count($rows) > 2000) {
            return response()->json(['message' => 'Please import at most 2,000 employees per file.'], 422);
        }

        $branch = $this->myBranchId() ? CompanyBranch::find($this->myBranchId()) : null;
        $analysis = $roster->analyze($rows, $company, $branch);

        $token = Str::random(40);
        Cache::put("roster_import:{$token}", [
            'company_id' => $company->id,
            'branch_id'  => $branch?->id,
            'user_id'    => auth()->id(),
            'rows'       => $rows,
        ], now()->addMinutes(30));

        return response()->json([
            'token'   => $token,
            'summary' => $analysis['summary'],
            'rows'    => array_map(fn ($r) => array_diff_key($r, ['data' => 1]), $analysis['rows']),
        ]);
    }

    /** Step 2: save the rows that are fine (re-checked now, in case anything changed). */
    public function confirm(Request $request, EmployeeRosterService $roster): JsonResponse
    {
        $company = $this->getMyCompany();
        $request->validate(['token' => 'required|string|size:40']);

        $pending = Cache::get("roster_import:{$request->token}");
        if (!$pending || (int) $pending['company_id'] !== (int) $company->id || (int) $pending['user_id'] !== (int) auth()->id()) {
            return response()->json(['message' => 'This preview has expired. Please upload the file again.'], 422);
        }
        Cache::forget("roster_import:{$request->token}");

        $branch = $pending['branch_id'] ? CompanyBranch::find($pending['branch_id']) : null;
        $analysis = $roster->analyze($pending['rows'], $company, $branch);
        if ($analysis['summary']['create'] + $analysis['summary']['update'] === 0) {
            return response()->json(['message' => 'Nothing to import: every row is either unchanged or has an error.'], 422);
        }

        $result = $roster->apply($analysis, $company, auth()->user());

        return response()->json($result + ['summary' => $analysis['summary']]);
    }

    public function resendInvite(Employee $employee): JsonResponse
    {
        $company = $this->getMyCompany();
        if ((int) $employee->company_id !== (int) $company->id) abort(403);
        $this->assertCanManage($employee);

        if ($employee->account_created_at) {
            return response()->json(['message' => 'This employee has already created their account.'], 422);
        }
        if ($employee->invited_at && $employee->invited_at->gt(now()->subMinutes(2))) {
            return response()->json(['message' => 'An invitation was just sent. Please wait a moment before sending another.'], 429);
        }

        EmployeeRosterService::invite($employee->load('user', 'company'));

        return response()->json(['message' => 'Invitation sent to ' . $employee->user?->email . '.']);
    }

    /** Bulk: re-invite everyone who has not created an account yet */
    public function resendInvites(): JsonResponse
    {
        $company = $this->getMyCompany();
        $list = $company->employees()->with('user', 'company')
            ->whereNull('account_created_at')
            ->when($this->myBranchId(), fn ($q, $b) => $q->where('company_branch_id', $b))
            ->where(fn ($q) => $q->whereNull('invited_at')->orWhere('invited_at', '<', now()->subMinutes(2)))
            ->get();

        foreach ($list as $e) {
            EmployeeRosterService::invite($e);
        }

        return response()->json(['message' => $list->count() . ' invitation(s) sent.', 'count' => $list->count()]);
    }

}
