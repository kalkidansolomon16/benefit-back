<?php

namespace App\Http\Controllers;

use App\Models\BillingInvoice;
use App\Models\BillingNegotiation;
use App\Models\BillingPayment;
use App\Models\Company;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CompanyBillingController extends Controller
{
    /* ── Get the company linked to the logged-in HR user ─────── */

    private function getMyCompany(): Company
    {
        $company = Company::where('contact_email', auth()->user()->email)->first();
        if (!$company) abort(403, 'No company linked to this account.');
        return $company;
    }

    /* ── Invoice list ─────────────────────────────────────────── */

    public function index(): JsonResponse
    {
        $company = $this->getMyCompany();

        // Auto-mark past-due sent invoices as overdue
        BillingInvoice::where('company_id', $company->id)
            ->where('status', 'sent')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now())
            ->update(['status' => 'overdue']);

        $invoices = BillingInvoice::where('company_id', $company->id)
            ->whereIn('status', ['sent', 'paid', 'overdue'])
            ->with(['items', 'latestPayment', 'latestNegotiation'])
            ->latest()
            ->get()
            ->map(fn($inv) => $this->formatInvoice($inv));

        return response()->json($invoices);
    }

    /* ── Single invoice detail ────────────────────────────────── */

    public function show(BillingInvoice $billingInvoice): JsonResponse
    {
        $company = $this->getMyCompany();
        if ($billingInvoice->company_id !== $company->id) abort(403);
        if ($billingInvoice->status === 'draft') abort(404);

        // Auto-mark overdue on detail view too
        if ($billingInvoice->status === 'sent'
            && $billingInvoice->due_date
            && now()->gt($billingInvoice->due_date)) {
            $billingInvoice->update(['status' => 'overdue']);
            $billingInvoice->refresh();
        }

        $billingInvoice->load(['items', 'payments', 'latestNegotiation']);

        return response()->json($this->formatInvoice($billingInvoice, true));
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
        if ($billingInvoice->company_id !== $company->id) abort(403);

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
        if ($billingInvoice->company_id !== $company->id) abort(403);

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
            'total_amount'   => $inv->total_amount,
            'due_date'       => $inv->due_date?->toDateString(),
            'notes'          => $inv->notes,
            'status'         => $inv->status,
            'sent_at'        => $inv->sent_at?->toDateString(),
            'paid_at'        => $inv->paid_at?->toDateString(),
            'items'          => $inv->items?->map(fn($i) => [
                'plan_tier'      => $i->plan_tier,
                'plan_name'      => $i->plan_name,
                'employee_count' => $i->employee_count,
                'unit_price'     => $i->unit_price,
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
                    ? Storage::disk('public')->url($p->receipt_path) : null,
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
