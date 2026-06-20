<?php

namespace App\Http\Controllers;

use App\Models\BillingInvoice;
use App\Models\BillingNegotiation;
use App\Models\BillingPayment;
use App\Models\Company;
use App\Models\Employee;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminBillingController extends Controller
{
    /* ── Helpers ──────────────────────────────────────────────── */

    /** Map employee level to its plan tier key. */
    /** Map employee level to its canonical tier key. */
    private function levelToTier(string $level): string
    {
        return match ($level) {
            'chief'    => 'platinum',
            'director' => 'basic_plus',
            'manager'  => 'basic_plus',
            default    => 'basic',
        };
    }

    /**
     * Find a plan by tier with fallback matching.
     *
     * Priority:
     *  1. Exact tier match        → 'basic'      matches 'basic'
     *  2. Suffix match            → 'fit_basic'  ends with '_basic'
     *  3. Prefix match            → 'basic_plus' starts with 'basic'
     *  4. Synonym (premium ↔ platinum), repeat steps 1-2
     *  5. Cheapest active plan as last resort (so billing never silently gives 0)
     */
    private function findPlanByTier(string $tier): ?MembershipPlan
    {
        // 1. Exact match
        $plan = MembershipPlan::where('tier', $tier)->where('is_active', true)->first();
        if ($plan) return $plan;

        // 2. Suffix match: 'fit_basic' ends with '_basic'
        $plan = MembershipPlan::where('is_active', true)
            ->where('tier', 'LIKE', '%_' . $tier)
            ->orderBy('monthly_fee_etb')
            ->first();
        if ($plan) return $plan;

        // 3. For 'basic': avoid matching 'fit_basic_plus' — already handled above
        //    but if tier is 'basic', also try tier that STARTS with 'basic' and is shortest
        if ($tier === 'basic') {
            $plan = MembershipPlan::where('is_active', true)
                ->where('tier', 'LIKE', 'basic%')
                ->orderBy('monthly_fee_etb')
                ->first();
            if ($plan) return $plan;
        }

        // 4. Synonym: platinum ↔ premium
        $synonym = match ($tier) {
            'platinum' => 'premium',
            'premium'  => 'platinum',
            default    => null,
        };
        if ($synonym) {
            $plan = MembershipPlan::where('tier', $synonym)->where('is_active', true)->first()
                 ?? MembershipPlan::where('is_active', true)
                        ->where('tier', 'LIKE', '%_' . $synonym)
                        ->orderBy('monthly_fee_etb', 'desc')
                        ->first();
            if ($plan) return $plan;
        }

        // 5. Last resort: pick cheapest active plan so price is never silently 0
        return MembershipPlan::where('is_active', true)->orderBy('monthly_fee_etb')->first();
    }

    /* ── Invoice list ─────────────────────────────────────────── */

    public function index(Request $request): JsonResponse
    {
        $invoices = BillingInvoice::with(['company:id,name', 'items', 'latestPayment'])
            ->when($request->company_id, fn($q) => $q->where('company_id', $request->company_id))
            ->when($request->status,     fn($q) => $q->where('status', $request->status))
            ->latest()
            ->get()
            ->map(fn($inv) => $this->formatInvoice($inv));

        return response()->json($invoices);
    }

    /* ── Generate invoice for a company ──────────────────────── */

    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'company_id'     => 'required|exists:companies,id',
            'billing_period' => 'required|string|max:30',
            'due_date'       => 'nullable|date',
            'notes'          => 'nullable|string|max:1000',
            'employee_id'    => 'nullable|exists:employees,id',
        ]);

        $company   = Company::findOrFail($request->company_id);
        $singleEmp = $request->filled('employee_id');

        // Build employee query
        $empQuery = Employee::where('company_id', $company->id)
            ->where('registration_status', 'approved')
            ->where('is_enrolled', true);

        if ($singleEmp) {
            // Pay Now flow: invoice for one specific employee only
            $empQuery->where('id', $request->employee_id);
        } else {
            // Batch flow: only bill employees whose payment is still unpaid
            $empQuery->where('payment_status', 'unpaid');
        }

        $employees = $empQuery->get();

        if ($employees->isEmpty()) {
            $msg = $singleEmp
                ? 'Employee not found or not eligible for invoicing.'
                : 'This company has no unpaid enrolled employees to invoice.';
            return response()->json(['message' => $msg], 422);
        }

        // Group employees by tier, look up plan prices
        $planPriceCache = [];
        $groups = [];

        foreach ($employees as $emp) {
            $tier = $this->levelToTier($emp->level ?? 'staff');

            if (!isset($planPriceCache[$tier])) {
                $plan = $this->findPlanByTier($tier);
                $planPriceCache[$tier] = [
                    'name'  => $plan?->name ?? ucfirst(str_replace('_', ' ', $tier)),
                    'price' => (float) ($plan?->monthly_fee_etb ?? 0),
                ];
            }

            $groups[$tier] = ($groups[$tier] ?? 0) + 1;
        }

        $total = 0;
        $items = [];
        foreach ($groups as $tier => $count) {
            $unitPrice = $planPriceCache[$tier]['price'];
            $subtotal  = $count * $unitPrice;
            $total    += $subtotal;

            $items[] = [
                'plan_tier'      => $tier,
                'plan_name'      => $planPriceCache[$tier]['name'],
                'employee_count' => $count,
                'unit_price'     => $unitPrice,
                'subtotal'       => $subtotal,
            ];
        }

        if ($total < 100) {
            $details = [];
            foreach ($planPriceCache as $tier => $info) {
                $details[] = "tier '{$tier}' → " . ($info['price'] > 0 ? "ETB {$info['price']} ({$info['name']})" : "no plan found (ETB 0)");
            }
            $detailStr = $details ? ' Plan lookup: ' . implode('; ', $details) . '.' : '';
            return response()->json([
                'message' => "Invoice total is ETB {$total} — invoices must be at least ETB 100.{$detailStr} Make sure active membership plans exist with tiers matching your employees' levels.",
            ], 422);
        }

        return DB::transaction(function () use ($request, $company, $items, $total): JsonResponse {
            $invoice = BillingInvoice::create([
                'company_id'     => $company->id,
                'billing_period' => $request->billing_period,
                'total_amount'   => $total,
                'due_date'       => $request->due_date,
                'notes'          => $request->notes,
                'status'         => 'draft',
            ]);

            foreach ($items as $item) {
                $invoice->items()->create($item);
            }

            $invoice->load('items');

            return response()->json([
                'message' => "Invoice {$invoice->invoice_number} generated for {$company->name}.",
                'invoice' => $this->formatInvoice($invoice),
            ], 201);
        });
    }

    /* ── Show single invoice ──────────────────────────────────── */

    public function show(BillingInvoice $billingInvoice): JsonResponse
    {
        $billingInvoice->load(['company:id,name,contact_email,contact_phone', 'items', 'payments.verifiedBy:id,name']);
        return response()->json($this->formatInvoice($billingInvoice, true));
    }

    /* ── Send invoice to company ──────────────────────────────── */

    public function send(BillingInvoice $billingInvoice): JsonResponse
    {
        if ($billingInvoice->status !== 'draft') {
            return response()->json(['message' => 'Only draft invoices can be sent.'], 422);
        }

        $billingInvoice->update([
            'status'  => 'sent',
            'sent_at' => now(),
        ]);

        return response()->json(['message' => 'Invoice sent to company.', 'invoice' => $this->formatInvoice($billingInvoice)]);
    }

    /* ── Delete draft invoice ─────────────────────────────────── */

    public function destroy(BillingInvoice $billingInvoice): JsonResponse
    {
        if ($billingInvoice->status !== 'draft') {
            return response()->json(['message' => 'Only draft invoices can be deleted.'], 422);
        }

        $billingInvoice->delete();
        return response()->json(['message' => 'Invoice deleted.']);
    }

    /* ── List pending payments (receipts to review) ───────────── */

    public function pendingPayments(): JsonResponse
    {
        $payments = BillingPayment::with([
            'invoice:id,invoice_number,billing_period,total_amount,company_id',
            'company:id,name',
        ])
            ->where('status', 'pending')
            ->latest()
            ->get()
            ->map(fn($p) => $this->formatPayment($p));

        return response()->json($payments);
    }

    /* ── Verify payment → mark employees as paid ─────────────── */

    public function verifyPayment(Request $request, BillingPayment $billingPayment): JsonResponse
    {
        if ($billingPayment->status !== 'pending') {
            return response()->json(['message' => 'This payment has already been processed.'], 422);
        }

        $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($request, $billingPayment): JsonResponse {
            // Mark payment as verified
            $billingPayment->update([
                'status'      => 'verified',
                'verified_at' => now(),
                'verified_by' => auth()->id(),
                'admin_notes' => $request->notes,
            ]);

            // Mark invoice as paid
            $billingPayment->invoice->update([
                'status'  => 'paid',
                'paid_at' => now(),
            ]);

            // Mark all enrolled employees of this company as paid
            Employee::where('company_id', $billingPayment->company_id)
                ->where('is_enrolled', true)
                ->where('registration_status', 'approved')
                ->update(['payment_status' => 'paid']);

            $result = MembershipService::provisionForCompany($billingPayment->company_id);

            return response()->json([
                'message' => sprintf(
                    'Payment verified. %d employee(s) are now marked as paid and %d gym membership(s) have been activated.',
                    $result['employees'],
                    $result['memberships']
                ),
                'employees_updated'   => $result['employees'],
                'memberships_created' => $result['memberships'],
            ]);
        });
    }

    /* ── Reject payment ───────────────────────────────────────── */

    public function rejectPayment(Request $request, BillingPayment $billingPayment): JsonResponse
    {
        if ($billingPayment->status !== 'pending') {
            return response()->json(['message' => 'This payment has already been processed.'], 422);
        }

        $request->validate([
            'notes' => 'required|string|max:500',
        ]);

        $billingPayment->update([
            'status'      => 'rejected',
            'verified_at' => now(),
            'verified_by' => auth()->id(),
            'admin_notes' => $request->notes,
        ]);

        // Revert invoice back to sent so company can resubmit
        $billingPayment->invoice->update(['status' => 'sent']);

        return response()->json(['message' => 'Payment rejected. Company notified to resubmit.']);
    }

    /* ── Pending negotiations ─────────────────────────────────── */

    public function pendingNegotiations(): JsonResponse
    {
        $negotiations = BillingNegotiation::with([
            'invoice:id,invoice_number,billing_period,total_amount,due_date,company_id',
            'company:id,name',
        ])
            ->where('status', 'pending')
            ->latest('submitted_at')
            ->get()
            ->map(fn($n) => $this->formatNegotiation($n));

        return response()->json($negotiations);
    }

    /* ── Approve negotiation ──────────────────────────────────── */

    public function approveNegotiation(Request $request, BillingNegotiation $billingNegotiation): JsonResponse
    {
        if ($billingNegotiation->status !== 'pending') {
            return response()->json(['message' => 'This negotiation has already been reviewed.'], 422);
        }

        $request->validate(['admin_notes' => 'nullable|string|max:1000']);

        $billingNegotiation->update([
            'status'      => 'approved',
            'admin_notes' => $request->admin_notes,
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
        ]);

        // Revert invoice back to 'sent' so the company can now proceed to pay
        $billingNegotiation->invoice->update(['status' => 'sent']);

        return response()->json(['message' => 'Negotiation approved. Company can now submit payment.']);
    }

    /* ── Reject negotiation ───────────────────────────────────── */

    public function rejectNegotiation(Request $request, BillingNegotiation $billingNegotiation): JsonResponse
    {
        if ($billingNegotiation->status !== 'pending') {
            return response()->json(['message' => 'This negotiation has already been reviewed.'], 422);
        }

        $request->validate(['admin_notes' => 'required|string|max:1000']);

        $billingNegotiation->update([
            'status'      => 'rejected',
            'admin_notes' => $request->admin_notes,
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
        ]);

        return response()->json([
            'message'    => 'Negotiation rejected.',
            'company_id' => $billingNegotiation->company_id,
        ]);
    }

    /* ── Deactivate or ban company after rejected negotiation ─── */

    public function deactivateCompany(Request $request, Company $company): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:suspend,ban',
            'reason' => 'required|string|min:10|max:1000',
        ]);

        return DB::transaction(function () use ($request, $company): JsonResponse {
            $status = $request->action === 'ban' ? 'banned' : 'suspended';

            // Update company
            $company->update([
                'is_active'         => false,
                'company_status'    => $status,
                'suspension_reason' => $request->reason,
            ]);

            // Suspend all enrolled employees
            Employee::where('company_id', $company->id)
                ->where('is_enrolled', true)
                ->update(['is_enrolled' => false]);

            // Deactivate the HR user account
            User::where('email', $company->contact_email)->update(['is_active' => false]);

            $label = $status === 'banned' ? 'permanently banned' : 'suspended';

            return response()->json([
                'message' => "Company has been {$label}. All employee access has been revoked.",
                'status'  => $status,
            ]);
        });
    }

    /* ── Format helpers ───────────────────────────────────────── */

    private function formatNegotiation(BillingNegotiation $n): array
    {
        return [
            'id'             => $n->id,
            'billing_invoice_id' => $n->billing_invoice_id,
            'status'         => $n->status,
            'reason'         => $n->reason,
            'admin_notes'    => $n->admin_notes,
            'submitted_at'   => $n->submitted_at?->toDateTimeString(),
            'reviewed_at'    => $n->reviewed_at?->toDateTimeString(),
            'company'        => $n->company ? ['id' => $n->company->id, 'name' => $n->company->name] : null,
            'invoice'        => $n->invoice ? [
                'id'             => $n->invoice->id,
                'invoice_number' => $n->invoice->invoice_number,
                'billing_period' => $n->invoice->billing_period,
                'total_amount'   => $n->invoice->total_amount,
                'due_date'       => $n->invoice->due_date?->toDateString(),
            ] : null,
        ];
    }

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
            'sent_at'        => $inv->sent_at?->toDateTimeString(),
            'paid_at'        => $inv->paid_at?->toDateTimeString(),
            'created_at'     => $inv->created_at->toDateString(),
            'company'        => $inv->company ? [
                'id'            => $inv->company->id,
                'name'          => $inv->company->name,
                'contact_email' => $inv->company->contact_email ?? null,
                'contact_phone' => $inv->company->contact_phone ?? null,
            ] : null,
            'items' => $inv->items?->map(fn($item) => [
                'id'             => $item->id,
                'plan_tier'      => $item->plan_tier,
                'plan_name'      => $item->plan_name,
                'employee_count' => $item->employee_count,
                'unit_price'     => $item->unit_price,
                'subtotal'       => $item->subtotal,
            ])->values() ?? [],
            'latest_payment' => $inv->latestPayment ? $this->formatPayment($inv->latestPayment) : null,
        ];

        if ($withPayments && $inv->relationLoaded('payments')) {
            $data['payments'] = $inv->payments->map(fn($p) => $this->formatPayment($p))->values();
        }

        return $data;
    }

    private function formatPayment(BillingPayment $p): array
    {
        return [
            'id'                           => $p->id,
            'billing_invoice_id'           => $p->billing_invoice_id,
            'invoice_number'               => $p->invoice?->invoice_number,
            'billing_period'               => $p->invoice?->billing_period,
            'invoice_total'                => $p->invoice?->total_amount,
            'company'                      => $p->company ? ['id' => $p->company->id, 'name' => $p->company->name] : null,
            'amount'                       => $p->amount,
            'payment_method_bank'          => $p->payment_method_bank,
            'payment_method_account_name'  => $p->payment_method_account_name,
            'payment_method_account_number'=> $p->payment_method_account_number,
            'receipt_path'                 => $p->receipt_path ? Storage::disk('public')->url($p->receipt_path) : null,
            'status'                       => $p->status,
            'submitted_at'                 => $p->submitted_at?->toDateTimeString(),
            'verified_at'                  => $p->verified_at?->toDateTimeString(),
            'verified_by'                  => $p->verifiedBy?->name,
            'admin_notes'                  => $p->admin_notes,
        ];
    }
}
