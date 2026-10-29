<?php

namespace App\Http\Controllers;

use App\Models\BillingInvoice;
use App\Models\BillingNegotiation;
use App\Models\BillingPayment;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Employee;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\MembershipService;
use App\Services\TelegramService;
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
        // Plan tiers are typed by admins ("Fit Basic", "fit_basic_plus"), so compare them
        // in a normalised form. No cheapest-plan fallback here: billing must never
        // quietly price someone at the wrong plan.
        return MembershipService::resolvePlan($tier, false);
    }

    /* ── Invoice list ─────────────────────────────────────────── */

    public function index(Request $request): JsonResponse
    {
        $invoices = BillingInvoice::with(['company:id,name', 'companyBranch:id,name', 'items', 'latestPayment'])
            ->when($request->company_id, fn($q) => $q->where('company_id', $request->company_id))
            ->when($request->filled('company_branch_id'), fn($q) => $q->where('company_branch_id', $request->company_branch_id))
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
            // Bill just one branch: a branch id, or "main" for the head office
            'branch'         => 'nullable|max:20',
        ]);

        $company   = Company::findOrFail($request->company_id);
        $singleEmp = $request->filled('employee_id');

        // Build employee query
        $empQuery = Employee::where('company_id', $company->id)
            ->where('registration_status', 'approved')
            ->where('is_enrolled', true)
            ->where('payment_status', '!=', 'paid');

        if ($request->filled('branch')) {
            $branchParam = (string) $request->branch;
            if ($branchParam === 'main') {
                $empQuery->whereNull('company_branch_id');
            } elseif (ctype_digit($branchParam) && $company->branches()->whereKey((int) $branchParam)->exists()) {
                $empQuery->where('company_branch_id', (int) $branchParam);
            } else {
                return response()->json(['message' => 'That branch does not belong to this company.'], 422);
            }
        }

        if ($singleEmp) {
            // Pay Now flow: invoice for one specific employee only
            $empQuery->where('id', $request->employee_id);
        } else {
            // Batch flow: only bill employees not yet paid for THIS billing period.
            // paid_period (not the sticky payment_status flag) tracks which period an
            // employee was last settled for, so billing re-arms every new cycle.
            $empQuery->where(function ($q) use ($request) {
                $q->whereNull('paid_period')
                  ->orWhere('paid_period', '!=', $request->billing_period);
            });
        }

        $employees = $empQuery->with('activeMembership.plan')->get();

        if ($employees->isEmpty()) {
            $msg = $singleEmp
                ? 'Employee not found or not eligible for invoicing.'
                : 'This company has no enrolled employees still unpaid for this billing period.';
            return response()->json(['message' => $msg], 422);
        }

        $hasBranches = $company->branches()->exists();

        // A company with branches is billed per branch: one invoice for each branch
        // (the head office counts as its own), so every branch pays only for its own employees.
        $groups = $hasBranches
            ? $employees->groupBy(fn($e) => $e->company_branch_id ?? 0)
            : collect([0 => $employees]);

        $branchNames = $hasBranches
            ? $company->branches()->pluck('name', 'id')
            : collect();

        $created = [];
        $skipped = [];

        foreach ($groups as $branchKey => $group) {
            $branchId = $hasBranches && $branchKey ? (int) $branchKey : null;
            $label    = !$hasBranches
                ? $company->name
                : ($branchId ? ($branchNames[$branchId] ?? "Branch {$branchId}") : 'Head office');

            $built = $this->buildInvoiceItems($group);

            if ($built['total'] < 100) {
                $skipped[] = [
                    'branch'  => $label,
                    'total'   => $built['total'],
                    'details' => $built['details'],
                ];
                continue;
            }

            $created[] = DB::transaction(function () use ($request, $company, $built, $branchId, $hasBranches, $singleEmp): BillingInvoice {
                $invoice = BillingInvoice::create([
                    'company_id'        => $company->id,
                    'company_branch_id' => $branchId,
                    'branch_scoped'     => $hasBranches,
                    'employee_id'       => $singleEmp ? $request->employee_id : null,
                    'billing_period'    => $request->billing_period,
                    'total_amount'      => $built['total'],
                    'due_date'          => $request->due_date,
                    'notes'             => $request->notes,
                    'status'            => 'draft',
                ]);

                foreach ($built['items'] as $item) {
                    $invoice->items()->create($item);
                }

                return $invoice->load(['items', 'companyBranch:id,name']);
            });
        }

        if (!$created) {
            $first = $skipped[0];
            $where = $hasBranches ? " for {$first['branch']}" : '';
            return response()->json([
                'message' => "Invoice total{$where} is ETB {$first['total']} — invoices must be at least ETB 100.{$first['details']} Make sure active membership plans exist with tiers matching your employees' levels.",
            ], 422);
        }

        $message = count($created) === 1
            ? "Invoice {$created[0]->invoice_number} generated for {$company->name}"
                . ($hasBranches ? ' (' . ($created[0]->companyBranch?->name ?? 'Head office') . ')' : '') . '.'
            : count($created) . " invoices generated for {$company->name}, one per branch.";
        if ($skipped) {
            $message .= ' Skipped (below ETB 100): ' . implode(', ', array_map(fn($s) => $s['branch'], $skipped)) . '.';
        }

        return response()->json([
            'message'  => $message,
            'invoice'  => $this->formatInvoice($created[0]),
            'invoices' => array_map(fn($inv) => $this->formatInvoice($inv), $created),
            'skipped'  => $skipped,
        ], 201);
    }

    /**
     * Price a group of employees: resolve each one's level→tier→plan, then group by the
     * resolved plan so tiers that fall back to the same plan aren't shown as duplicate lines.
     *
     * @return array{items: array, total: float|int, details: string}
     */
    private function buildInvoiceItems($employees): array
    {
        $planByTier   = [];   // tier => ['planKey', 'planTier', 'planName', 'price']
        $groupsByPlan = [];   // planKey => ['plan_tier', 'plan_name', 'unit_price', 'count']

        foreach ($employees as $emp) {
            // Prefer the tier of the employee's active membership plan (most accurate);
            // fall back to level-based lookup for employees not yet provisioned.
            $activePlan = $emp->activeMembership?->plan;
            $tier = $activePlan?->tier ?? $this->levelToTier($emp->level ?? 'staff');

            if (!isset($planByTier[$tier])) {
                $plan = $activePlan && $activePlan->tier === $tier ? $activePlan : $this->findPlanByTier($tier);
                $price    = (float) ($plan?->monthly_fee_etb ?? 0);
                $planName = $plan?->name ?? ucfirst(str_replace('_', ' ', $tier));
                $planTier = $plan?->tier ?? $tier;
                $planKey  = $plan ? "plan_{$plan->id}" : "tier_{$tier}";
                $planByTier[$tier] = compact('planKey', 'planTier', 'planName', 'price');
            }

            $info    = $planByTier[$tier];
            $planKey = $info['planKey'];

            if (!isset($groupsByPlan[$planKey])) {
                $groupsByPlan[$planKey] = [
                    'plan_tier'  => $info['planTier'],
                    'plan_name'  => $info['planName'],
                    'unit_price' => $info['price'],
                    'count'      => 0,
                ];
            }
            $groupsByPlan[$planKey]['count']++;
        }

        $total = 0;
        $items = [];
        foreach ($groupsByPlan as $info) {
            $subtotal = $info['count'] * $info['unit_price'];
            $total   += $subtotal;

            $items[] = [
                'plan_tier'      => $info['plan_tier'],
                'plan_name'      => $info['plan_name'],
                'employee_count' => $info['count'],
                'unit_price'     => $info['unit_price'],
                'subtotal'       => $subtotal,
            ];
        }

        $details = [];
        foreach ($planByTier as $tier => $info) {
            $details[] = "tier '{$tier}' → " . ($info['price'] > 0 ? "ETB {$info['price']} ({$info['planName']})" : 'no plan found (ETB 0)');
        }

        return [
            'items'   => $items,
            'total'   => $total,
            'details' => $details ? ' Plan lookup: ' . implode('; ', $details) . '.' : '',
        ];
    }

    /* ── Show single invoice ──────────────────────────────────── */

    public function show(BillingInvoice $billingInvoice): JsonResponse
    {
        $billingInvoice->load(['company:id,name,contact_email,contact_phone', 'companyBranch:id,name', 'items', 'payments.verifiedBy:id,name']);
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

        try {
            app(TelegramService::class)->notifyCompanyInvoiceSent($billingInvoice);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Invoice sent Telegram failed: ' . $e->getMessage());
        }

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
            'invoice:id,invoice_number,billing_period,total_amount,company_id,company_branch_id,branch_scoped',
            'invoice.companyBranch:id,name',
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

        $result = DB::transaction(function () use ($request, $billingPayment): array {
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

            // Provision memberships + mark employees paid for this billing period.
            // If the invoice was scoped to a single employee (Pay Now), only that
            // employee is marked paid — not everyone under the company.
            return MembershipService::provisionForInvoice($billingPayment->invoice);
        });

        try {
            $billingPayment->loadMissing('invoice');
            app(TelegramService::class)->notifyCompanyPaymentVerified($billingPayment);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Payment verified Telegram failed: ' . $e->getMessage());
        }

        return response()->json([
            'message' => sprintf(
                'Payment verified. %d employee(s) are now marked as paid and %d gym membership(s) have been activated.',
                $result['employees'],
                $result['memberships']
            ),
            'employees_updated'   => $result['employees'],
            'memberships_created' => $result['memberships'],
        ]);
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

        try {
            $billingPayment->loadMissing('invoice');
            app(TelegramService::class)->notifyCompanyPaymentRejected($billingPayment, $request->notes);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Payment rejected Telegram failed: ' . $e->getMessage());
        }

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
            $company->setAccountsActive(false);   // branch logins too

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
            'branch_scoped'  => (bool) $inv->branch_scoped,
            'branch'         => $inv->branch_scoped
                ? ['id' => $inv->company_branch_id, 'name' => $inv->companyBranch?->name ?? 'Head office']
                : null,
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
            'branch'                       => $p->invoice?->branch_scoped
                ? ['id' => $p->invoice->company_branch_id, 'name' => $p->invoice->companyBranch?->name ?? 'Head office']
                : null,
            'company'                      => $p->company ? ['id' => $p->company->id, 'name' => $p->company->name] : null,
            'amount'                       => $p->amount,
            'payment_method_bank'          => $p->payment_method_bank,
            'payment_method_account_name'  => $p->payment_method_account_name,
            'payment_method_account_number'=> $p->payment_method_account_number,
            'receipt_path'                 => $p->receipt_path ? url('/api/v1/files/' . ltrim($p->receipt_path, '/')) : null,
            'status'                       => $p->status,
            'submitted_at'                 => $p->submitted_at?->toDateTimeString(),
            'verified_at'                  => $p->verified_at?->toDateTimeString(),
            'verified_by'                  => $p->verifiedBy?->name,
            'admin_notes'                  => $p->admin_notes,
        ];
    }
}
