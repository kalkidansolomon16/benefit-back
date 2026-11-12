<?php

namespace App\Http\Controllers;

use App\Models\BillingInvoice;
use App\Models\BillingNegotiation;
use App\Models\BillingPayment;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PaymentMethod;
use App\Support\BillingPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CompanyBillingController extends Controller
{
    /* ── Get the company linked to the logged-in HR user ─────── */

    private function getMyCompany(): Company
    {
        $user    = auth()->user();
        $company = ($user->company_id ? Company::find($user->company_id) : null)
            ?? Company::where('contact_email', $user->email)->first();
        if (!$company) abort(403, 'No company linked to this account.');
        return $company;
    }

    /** A branch login may only touch invoices issued to its own branch */
    private function authorizeInvoice(BillingInvoice $invoice, Company $company): void
    {
        if ($invoice->company_id !== $company->id) abort(403);
        if (!$invoice->isAccessibleBy(auth()->user())) {
            abort(403, 'This invoice belongs to another branch. Only that branch or the head office can view or pay it.');
        }
    }

    /* ── Invoice list ─────────────────────────────────────────── */

    public function index(Request $request): JsonResponse
    {
        $company = $this->getMyCompany();
        $user    = auth()->user();

        // Auto-mark past-due sent invoices as overdue
        BillingInvoice::where('company_id', $company->id)
            ->where('status', 'sent')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now())
            ->update(['status' => 'overdue']);

        $invoices = BillingInvoice::where('company_id', $company->id)
            ->whereIn('status', ['sent', 'paid', 'overdue'])
            ->when($user->isCompanyBranchLogin(), fn($q) => $q
                ->where('branch_scoped', true)
                ->where('company_branch_id', $user->company_branch_id))
            ->when(!$user->isCompanyBranchLogin() && $request->filled('company_branch_id'), fn($q) => $request->company_branch_id === 'main'
                ? $q->where('branch_scoped', true)->whereNull('company_branch_id')
                : $q->where('company_branch_id', (int) $request->company_branch_id))
            ->with(['items', 'latestPayment', 'latestNegotiation', 'companyBranch:id,name'])
            ->latest()
            ->get()
            ->map(fn($inv) => $this->formatInvoice($inv));

        return response()->json($invoices);
    }

    /* ── Single invoice detail ────────────────────────────────── */

    public function show(BillingInvoice $billingInvoice): JsonResponse
    {
        $company = $this->getMyCompany();
        $this->authorizeInvoice($billingInvoice, $company);
        if ($billingInvoice->status === 'draft') abort(404);

        // Auto-mark overdue on detail view too
        if ($billingInvoice->status === 'sent'
            && $billingInvoice->due_date
            && now()->gt($billingInvoice->due_date)) {
            $billingInvoice->update(['status' => 'overdue']);
            $billingInvoice->refresh();
        }

        $billingInvoice->load(['items', 'payments', 'latestNegotiation', 'companyBranch:id,name']);

        return response()->json($this->formatInvoice($billingInvoice, true));
    }

    /* ── Coverage summary + billing preference ─────────────────── */

    /**
     * How long the company's employees are paid for, and how often the company
     * prefers to be invoiced. A branch login sees only its own employees.
     */
    public function coverage(): JsonResponse
    {
        $company = $this->getMyCompany();
        $user    = auth()->user();

        $employees = Employee::where('company_id', $company->id)
            ->where('registration_status', 'approved')
            ->where('is_enrolled', true)
            ->when($user->isCompanyBranchLogin(), fn($q) => $q->where('company_branch_id', $user->company_branch_id))
            ->get(['id', 'paid_until']);

        $today = now()->startOfDay();
        $soon  = $today->copy()->addDays(max(1, (int) config('fitaccess.billing_reminder_days', 7)));

        $covered  = $employees->filter(fn($e) => $e->hasPaidAccess());
        $expired  = $employees->filter(fn($e) => !$e->hasPaidAccess() && $e->paid_until);
        $never    = $employees->filter(fn($e) => !$e->paid_until);
        $expiring = $covered->filter(fn($e) => $e->paid_until && $e->paid_until->lte($soon));

        $months = in_array((int) $company->billing_cycle_months, BillingPeriod::CYCLES, true) ? (int) $company->billing_cycle_months : 1;

        return response()->json([
            'billing_cycle_months' => $months,
            'billing_cycle_label'  => BillingPeriod::label($months),
            'cycles'               => array_map(fn($m) => ['months' => $m, 'label' => BillingPeriod::label($m)], BillingPeriod::CYCLES),
            'can_change'           => !$user->isCompanyBranchLogin(),
            'employees'            => $employees->count(),
            'covered'              => $covered->count(),
            'expired'              => $expired->count(),
            'not_paid_yet'         => $never->count(),
            'expiring_soon'        => $expiring->count(),
            'covered_until'        => $covered->min('paid_until')?->toDateString(),
            'covered_until_latest' => $covered->max('paid_until')?->toDateString(),
            'grace_days'           => BillingPeriod::graceDays(),
        ]);
    }

    /** Head office only: how often the company prefers to be invoiced */
    public function updatePreference(Request $request): JsonResponse
    {
        $company = $this->getMyCompany();
        if (auth()->user()->isCompanyBranchLogin()) {
            abort(403, 'Only the head office can change how often the company is invoiced.');
        }

        $data = $request->validate([
            'billing_cycle_months' => 'required|integer|in:' . implode(',', BillingPeriod::CYCLES),
        ]);
        $company->update($data);

        return response()->json([
            'message'              => 'Billing preference saved: ' . strtolower(BillingPeriod::label($data['billing_cycle_months'])) . '. Your next invoice will cover this period.',
            'billing_cycle_months' => (int) $data['billing_cycle_months'],
        ]);
    }

    /* ── Active payment methods (for dropdown) ────────────────── */

    public function paymentMethods(): JsonResponse
    {
        $methods = PaymentMethod::where('is_active', true)
            ->orderBy('bank_name')
            ->get(['id', 'bank_name', 'account_name', 'account_number', 'instructions']);

        return response()->json($methods);
    }

    /* ── Submit payment with receipt upload ───────────────────── */

    public function submitPayment(Request $request, BillingInvoice $billingInvoice): JsonResponse
    {
        $company = $this->getMyCompany();
        $this->authorizeInvoice($billingInvoice, $company);

        if (!in_array($billingInvoice->status, ['sent', 'overdue'])) {
            return response()->json(['message' => 'This invoice is not awaiting payment.'], 422);
        }

        $pending = $billingInvoice->payments()->where('status', 'pending')->exists();
        if ($pending) {
            return response()->json(['message' => 'A payment is already submitted and under review.'], 422);
        }

        $request->validate([
            'payment_method_id' => 'required|exists:payment_methods,id',
            'receipt'           => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $method      = PaymentMethod::findOrFail($request->payment_method_id);
        $receiptPath = $request->file('receipt')->store('payment-receipts', 'public');

        $payment = BillingPayment::create([
            'billing_invoice_id'            => $billingInvoice->id,
            'company_id'                    => $company->id,
            'amount'                        => $billingInvoice->total_amount,
            'payment_method_id'             => $method->id,
            'payment_method_bank'           => $method->bank_name,
            'payment_method_account_name'   => $method->account_name,
            'payment_method_account_number' => $method->account_number,
            'receipt_path'                  => $receiptPath,
            'status'                        => 'pending',
            'submitted_at'                  => now(),
        ]);

        return response()->json([
            'message'    => 'Payment submitted successfully. Our team will verify it within 1–2 business days.',
            'payment_id' => $payment->id,
        ], 201);
    }

    /* ── Submit negotiation request ───────────────────────────── */

    public function submitNegotiation(Request $request, BillingInvoice $billingInvoice): JsonResponse
    {
        $company = $this->getMyCompany();
        $this->authorizeInvoice($billingInvoice, $company);

        if ($billingInvoice->status !== 'overdue') {
            return response()->json(['message' => 'Negotiation can only be requested for overdue invoices.'], 422);
        }

        // Block if there is already a pending or approved negotiation
        $existing = BillingNegotiation::where('billing_invoice_id', $billingInvoice->id)
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => $existing->status === 'pending'
                    ? 'A negotiation request is already pending. Please wait for admin review.'
                    : 'Your negotiation has already been approved. Proceed with payment.',
            ], 422);
        }

        $request->validate([
            'reason' => 'required|string|min:20|max:2000',
        ]);

        $negotiation = BillingNegotiation::create([
            'billing_invoice_id' => $billingInvoice->id,
            'company_id'         => $company->id,
            'reason'             => $request->reason,
            'status'             => 'pending',
            'submitted_at'       => now(),
        ]);

        return response()->json([
            'message'     => 'Negotiation request submitted. Admin will review it shortly.',
            'negotiation' => $this->formatNegotiation($negotiation),
        ], 201);
    }

    /* ── Format helpers ───────────────────────────────────────── */

    private function formatInvoice(BillingInvoice $inv, bool $withPayments = false): array
    {
        $data = [
            'id'             => $inv->id,
            'invoice_number' => $inv->invoice_number,
            'billing_period' => $inv->billing_period,
            'period_months'  => (int) ($inv->period_months ?: 1),
            'period_start'   => $inv->period_start?->toDateString(),
            'period_end'     => $inv->period_end?->toDateString(),
            'total_amount'   => $inv->total_amount,
            'due_date'       => $inv->due_date?->toDateString(),
            'notes'          => $inv->notes,
            'status'         => $inv->status,
            'branch_scoped'  => (bool) $inv->branch_scoped,
            'branch'         => $inv->branch_scoped
                ? ['id' => $inv->company_branch_id, 'name' => $inv->companyBranch?->name ?? 'Head office']
                : null,
            'sent_at'        => $inv->sent_at?->toDateString(),
            'paid_at'        => $inv->paid_at?->toDateString(),
            'items'          => $inv->items?->map(fn($i) => [
                'plan_tier'      => $i->plan_tier,
                'plan_name'      => $i->plan_name,
                'employee_count' => $i->employee_count,
                'unit_price'     => $i->unit_price,
                'months'         => (int) ($i->months ?: 1),
                'subtotal'       => $i->subtotal,
            ])->values() ?? [],
            'latest_payment' => $inv->latestPayment ? [
                'status'       => $inv->latestPayment->status,
                'submitted_at' => $inv->latestPayment->submitted_at?->toDateTimeString(),
                'admin_notes'  => $inv->latestPayment->admin_notes,
            ] : null,
            'negotiation' => $inv->latestNegotiation
                ? $this->formatNegotiation($inv->latestNegotiation)
                : null,
        ];

        if ($withPayments) {
            $data['payments'] = $inv->payments?->map(fn($p) => [
                'id'                            => $p->id,
                'amount'                        => $p->amount,
                'payment_method_bank'           => $p->payment_method_bank,
                'payment_method_account_name'   => $p->payment_method_account_name,
                'payment_method_account_number' => $p->payment_method_account_number,
                'receipt_url'                   => $p->receipt_path
                    ? url('/api/v1/files/' . ltrim($p->receipt_path, '/')) : null,
                'status'                        => $p->status,
                'submitted_at'                  => $p->submitted_at?->toDateTimeString(),
                'admin_notes'                   => $p->admin_notes,
            ])->values() ?? [];
        }

        return $data;
    }

    private function formatNegotiation(BillingNegotiation $n): array
    {
        return [
            'id'           => $n->id,
            'status'       => $n->status,
            'reason'       => $n->reason,
            'admin_notes'  => $n->admin_notes,
            'submitted_at' => $n->submitted_at?->toDateTimeString(),
            'reviewed_at'  => $n->reviewed_at?->toDateTimeString(),
        ];
    }
}
