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
use App\Services\BillingMailer;
use App\Services\TelegramService;
use App\Support\BillingPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminBillingController extends Controller
{
    /* ── Helpers ──────────────────────────────────────────────── */

    /** Map employee level to its plan tier key. */
    /** Map employee level to its canonical tier key. */
    private function levelToTier(string $level): string
    {
        return \App\Support\Tier::forLevel($level);
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

    /**
     * Work out what an invoice would contain, without saving anything.
     *
     * Employees are billed when they are approved, enrolled and not yet covered
     * on the period's start day (paid_until is empty or before it). Employees
     * already on another unpaid invoice are left out so nobody is billed twice.
     *
     * @return array|JsonResponse
     */
    private function planInvoice(Request $request)
    {
        $request->validate([
            'company_id'     => 'required|exists:companies,id',
            'period_months'  => 'nullable|integer|in:' . implode(',', BillingPeriod::CYCLES),
            'period_start'   => 'nullable|date',
            'due_date'       => 'nullable|date',
            'notes'          => 'nullable|string|max:1000',
            'employee_id'    => 'nullable|exists:employees,id',
            // Bill just one branch: a branch id, or "main" for the head office
            'branch'         => 'nullable|max:20',
        ]);

        $company   = Company::findOrFail($request->company_id);
        $singleEmp = $request->filled('employee_id');
        $branch    = $request->filled('branch') ? (string) $request->branch : null;

        if ($branch !== null && $branch !== 'main'
            && !(ctype_digit($branch) && $company->branches()->whereKey((int) $branch)->exists())) {
            return response()->json(['message' => 'That branch does not belong to this company.'], 422);
        }

        $preferred = in_array((int) $company->billing_cycle_months, BillingPeriod::CYCLES, true)
            ? (int) $company->billing_cycle_months : 1;
        $months = $request->filled('period_months') ? (int) $request->period_months : $preferred;
        if ($request->filled('period_start')) {
            $start = Carbon::parse($request->period_start)->startOfDay();
        } elseif ($singleEmp) {
            // One employee: start the day after their own paid period (or today)
            $paidUntil = Employee::whereKey($request->employee_id)->value('paid_until');
            $next      = $paidUntil ? Carbon::parse($paidUntil)->addDay()->startOfDay() : null;
            $start     = $next && $next->gt(now()->startOfDay()) ? $next : now()->startOfDay();
        } else {
            // Fixed calendar: periods start on the 1st. This month while it can
            // still be paid (until the late deadline), otherwise next month; and
            // never a month everyone is already paid for.
            $start = \App\Support\BillingCalendar::nextPeriodStart();
            $uncovered = BillingPeriod::suggestedStart($company->id, $branch);
            $uncoveredMonth = $uncovered->day === 1 ? $uncovered : $uncovered->copy()->startOfMonth();
            if ($uncoveredMonth->gt($start)) {
                $start = $uncoveredMonth;
            }
        }
        $end    = BillingPeriod::endFor($start, $months);

        $empQuery = Employee::where('company_id', $company->id)
            ->where('registration_status', 'approved')
            ->where('is_enrolled', true);

        if ($branch === 'main') {
            $empQuery->whereNull('company_branch_id');
        } elseif ($branch !== null) {
            $empQuery->where('company_branch_id', (int) $branch);
        }
        if ($singleEmp) {
            // Pay Now flow: invoice for one specific employee only
            $empQuery->where('id', $request->employee_id);
        }

        $candidates = $empQuery->with('activeMembership.plan', 'user')->get();

        // Already paid for the start day → not billed again
        $alreadyCovered = $candidates->filter(fn($e) => $e->paid_until && $e->paid_until->gte($start))->count();
        $candidates     = $candidates->reject(fn($e) => $e->paid_until && $e->paid_until->gte($start));

        // Already on an invoice that is still waiting for payment → not billed twice
        $onOpenInvoice = DB::table('billing_invoice_employees')
            ->join('billing_invoices', 'billing_invoices.id', '=', 'billing_invoice_employees.billing_invoice_id')
            ->whereNull('billing_invoices.deleted_at')
            ->whereIn('billing_invoices.status', ['draft', 'sent', 'overdue'])
            ->whereIn('billing_invoice_employees.employee_id', $candidates->pluck('id'))
            ->pluck('billing_invoice_employees.employee_id')
            ->unique();
        $alreadyInvoiced = $onOpenInvoice->count();
        $employees = $candidates->reject(fn($e) => $onOpenInvoice->contains($e->id))->values();

        $hasBranches = $company->branches()->exists();
        $branchNames = $hasBranches ? $company->branches()->pluck('name', 'id') : collect();

        // A company with branches is billed per branch: one invoice for each branch
        // (the head office counts as its own), so every branch pays only for its own employees.
        $groups = [];
        $buckets = $hasBranches
            ? $employees->groupBy(fn($e) => $e->company_branch_id ?? 0)
            : collect($employees->isEmpty() ? [] : [0 => $employees]);

        foreach ($buckets as $branchKey => $group) {
            $branchId = $hasBranches && $branchKey ? (int) $branchKey : null;
            $groups[] = [
                'branch_id' => $branchId,
                'label'     => !$hasBranches
                    ? $company->name
                    : ($branchId ? ($branchNames[$branchId] ?? "Branch {$branchId}") : 'Head office'),
                'employees' => $group,
                'built'     => $this->buildInvoiceItems($group, $months, $start),
            ];
        }

        return compact('company', 'singleEmp', 'hasBranches', 'preferred', 'months', 'start', 'end',
            'groups', 'alreadyCovered', 'alreadyInvoiced');
    }

    /** What the invoice(s) would contain, for the admin's preview */
    public function preview(Request $request): JsonResponse
    {
        $plan = $this->planInvoice($request);
        if ($plan instanceof JsonResponse) {
            return $plan;
        }

        $groups = array_map(fn($g) => [
            'label'      => $g['label'],
            'employees'  => $g['employees']->count(),
            'items'      => $g['built']['items'],
            'total'      => $g['built']['total'],
            'zero_plans' => $g['built']['zero_plans'],
        ], $plan['groups']);

        return response()->json([
            'company'          => ['id' => $plan['company']->id, 'name' => $plan['company']->name],
            'preferred_months' => $plan['preferred'],
            'period_months'    => $plan['months'],
            'period_start'     => $plan['start']->toDateString(),
            'period_end'       => $plan['end']->toDateString(),
            'period_text'      => BillingPeriod::rangeText($plan['start'], $plan['end']),
            'due_date'         => \App\Support\BillingCalendar::dueDate($plan['start'])->toDateString(),
            'closes_on'        => \App\Support\BillingCalendar::closesOn($plan['start'])->toDateString(),
            'payment_window'   => \App\Support\BillingCalendar::windowText($plan['start']),
            'late_fee_percent' => \App\Support\BillingCalendar::lateFeePercent(),
            'employees'        => array_sum(array_column($groups, 'employees')),
            'total'            => array_sum(array_column($groups, 'total')),
            'groups'           => $groups,
            'already_covered'  => $plan['alreadyCovered'],
            'already_invoiced' => $plan['alreadyInvoiced'],
            // Billed employees who were paid before: their paid period ends before this start (a renewal)
            'renewals'         => array_sum(array_map(fn($g) => $g['employees']->whereNotNull('paid_until')->count(), $plan['groups'])),
            'zero_plans'       => array_values(array_unique(array_merge([], ...array_column($groups, 'zero_plans')))),
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $plan = $this->planInvoice($request);
        if ($plan instanceof JsonResponse) {
            return $plan;
        }
        ['company' => $company, 'singleEmp' => $singleEmp, 'hasBranches' => $hasBranches,
         'months' => $months, 'start' => $start, 'end' => $end] = $plan;

        if (!$plan['groups']) {
            $why = [];
            if ($plan['alreadyCovered'])  $why[] = "{$plan['alreadyCovered']} already paid until after " . $start->format('j M Y');
            if ($plan['alreadyInvoiced']) $why[] = "{$plan['alreadyInvoiced']} already on an unpaid invoice";
            $msg = $singleEmp
                ? 'This employee cannot be invoiced for this period.'
                : 'No employees to invoice for this period.';
            return response()->json(['message' => $msg . ($why ? ' (' . implode('; ', $why) . ')' : '')], 422);
        }

        $label   = BillingPeriod::rangeText($start, $end);
        $created = [];
        $skipped = [];

        foreach ($plan['groups'] as $g) {
            $built = $g['built'];
            if ($built['total'] < 100) {
                $skipped[] = ['branch' => $g['label'], 'total' => $built['total'], 'details' => $built['details']];
                continue;
            }

            $created[] = DB::transaction(function () use ($request, $company, $built, $g, $hasBranches, $singleEmp, $months, $start, $end, $label): BillingInvoice {
                $invoice = BillingInvoice::create([
                    'company_id'        => $company->id,
                    'company_branch_id' => $g['branch_id'],
                    'branch_scoped'     => $hasBranches,
                    'employee_id'       => $singleEmp ? $request->employee_id : null,
                    'billing_period'    => $label,
                    'period_months'     => $months,
                    'period_start'      => $start->toDateString(),
                    'period_end'        => $end->toDateString(),
                    'total_amount'      => $built['total'],
                    // Fixed calendar: pay in the window (e.g. 1–4), late with a fee until the 7th, then closed
                    'due_date'          => $request->due_date ?: \App\Support\BillingCalendar::dueDate($start)->toDateString(),
                    'closes_on'         => \App\Support\BillingCalendar::closesOn($start)->toDateString(),
                    'notes'             => $request->notes,
                    'status'            => 'draft',
                ]);

                foreach ($built['items'] as $item) {
                    $invoice->items()->create($item);
                }
                $invoice->coveredEmployees()->attach($g['employees']->pluck('id')->all());

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
                . ($hasBranches ? ' (' . ($created[0]->companyBranch?->name ?? 'Head office') . ')' : '')
                . ", covering {$label}."
            : count($created) . " invoices generated for {$company->name}, one per branch, covering {$label}.";
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
     * Each line is employees × monthly price × months.
     *
     * @return array{items: array, total: float|int, details: string, zero_plans: array}
     */
    private function buildInvoiceItems($employees, int $months = 1, ?Carbon $start = null): array
    {
        $planByTier   = [];   // tier => ['planKey', 'planTier', 'planName', 'price']
        $extras       = [];   // prorated extra-day lines for late joiners
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

            // Late joiner (added after the staff-list cut-off): charge the extra days
            // they already had access before this invoice's period, prorated by day.
            if ($start && $emp->prorate_from && $emp->prorate_from->lt($start) && $info['price'] > 0) {
                $to = $start->copy()->subDay();
                $amount = \App\Support\BillingCalendar::prorate((float) $info['price'], $emp->prorate_from, $to);
                if ($amount > 0) {
                    $extras[] = [
                        'plan_tier'      => $info['planTier'],
                        'plan_name'      => mb_substr('Extra days: ' . ($emp->user?->name ?? 'Employee') . ' (' . $emp->prorate_from->format('j M') . ' - ' . $to->format('j M Y') . ', ' . $info['planName'] . ')', 0, 100),
                        'employee_count' => 1,
                        'unit_price'     => $amount,
                        'months'         => 1,
                        'subtotal'       => $amount,
                    ];
                }
            }
        }

        $total = 0;
        $items = [];
        $zeroPlans = [];
        foreach ($groupsByPlan as $info) {
            $subtotal = $info['count'] * $info['unit_price'] * $months;
            $total   += $subtotal;
            if (!$info['unit_price']) {
                $zeroPlans[] = $info['plan_name'];
            }

            $items[] = [
                'plan_tier'      => $info['plan_tier'],
                'plan_name'      => $info['plan_name'],
                'employee_count' => $info['count'],
                'unit_price'     => $info['unit_price'],
                'months'         => $months,
                'subtotal'       => $subtotal,
            ];
        }

        foreach ($extras as $x) {
            $items[] = $x;
            $total += $x['subtotal'];
        }

        $details = [];
        foreach ($planByTier as $tier => $info) {
            $details[] = "tier '{$tier}' → " . ($info['price'] > 0 ? "ETB {$info['price']} ({$info['planName']})" : 'no plan found (ETB 0)');
        }

        return [
            'items'      => $items,
            'total'      => $total,
            'details'    => $details ? ' Plan lookup: ' . implode('; ', $details) . '.' : '',
            'zero_plans' => $zeroPlans,
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
        $emailed = BillingMailer::invoiceSent($billingInvoice->load(['company', 'items', 'companyBranch:id,name']));
        if ($billingInvoice->company) {
            \App\Services\Notifier::company($billingInvoice->company, 'invoice_sent',
                "New invoice {$billingInvoice->invoice_number}: ETB " . number_format((float) $billingInvoice->total_amount, 2),
                'Payment is due on ' . ($billingInvoice->due_date?->format('j M Y') ?? 'the due date')
                . ($billingInvoice->closes_on ? ' (grace period until ' . $billingInvoice->closes_on->format('j M Y') . ').' : '.'),
                null, $billingInvoice->branch_scoped ? $billingInvoice->company_branch_id : null, ['link' => '/hr/billing']);
        }

        return response()->json([
            'message' => 'Invoice sent to company' . ($emailed ? ' and emailed to ' . implode(', ', $emailed) : '') . '.',
            'invoice' => $this->formatInvoice($billingInvoice),
        ]);
    }

    /** Bulk: send every draft (or the given ids) to its company in one go */
    public function sendDrafts(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => 'nullable|array|max:500', 'ids.*' => 'integer']);
        $drafts = BillingInvoice::where('status', 'draft')
            ->when(!empty($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))
            ->get();
        if ($drafts->isEmpty()) {
            return response()->json(['message' => 'There are no draft invoices to send.'], 422);
        }
        foreach ($drafts as $inv) {
            $this->send($inv);
        }

        return response()->json(['message' => $drafts->count() . ' invoice(s) sent to the companies.', 'sent' => $drafts->count()]);
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
        BillingMailer::paymentConfirmed($billingPayment->invoice->load(['company', 'companyBranch:id,name']), $result['employees']);
        $activation = $this->activatePaidEmployees($billingPayment->invoice);

        $until = $result['paid_until'] ? Carbon::parse($result['paid_until'])->format('j M Y') : null;

        return response()->json([
            'message' => sprintf(
                'Payment verified. %d employee(s) now have access%s, and %d new gym membership(s) were activated.',
                $result['employees'],
                $until ? " until {$until}" : '',
                $result['memberships']
            ),
            'paid_until'          => $result['paid_until'],
            'employees_updated'   => $result['employees'],
            'memberships_created' => $result['memberships'],
            'activation'          => $activation,
        ]);
    }

    /**
     * After a verified payment: give the paid employees final approval, turn
     * their accounts on, issue new card numbers to the company, and tell
     * employees whose existing card works again. Card numbers never change.
     */
    private function activatePaidEmployees(BillingInvoice $invoice): array
    {
        $invoice->loadMissing('company');
        $company = $invoice->company;
        if (!$company) return ['approved' => 0, 'issued' => 0, 'reactivated' => 0];

        $employees = $invoice->coveredEmployees()->with('user')->get();
        if ($employees->isEmpty()) {
            $employees = Employee::with('user')->where('company_id', $company->id)
                ->when($invoice->employee_id, fn ($q) => $q->where('id', $invoice->employee_id))
                ->where('payment_status', 'paid')
                ->whereDate('paid_until', '>=', $invoice->period_end ?? now())
                ->get();
        }

        $approved = 0;
        $toIssue = collect();
        $reactivated = 0;
        foreach ($employees as $e) {
            if (($e->registration_status ?? 'approved') !== 'approved') continue;

            // Late joiner's extra days were on this invoice: nothing more to bill for them
            if ($e->prorate_from && $invoice->period_start && $e->prorate_from->lt($invoice->period_start)) {
                $e->update(['prorate_from' => null]);
            }

            if ($e->admin_approval_status !== 'approved') {
                $e->update(['admin_approval_status' => 'approved', 'is_enrolled' => true, 'enrolled_at' => $e->enrolled_at ?? now()]);
                $approved++;
            }
            if ($e->user && !$e->user->is_active) {
                $e->user->update(['is_active' => true]);
            }

            if (!$e->card_issued_at) {
                $toIssue->push($e->fresh('user'));
            } elseif ($e->card_activated_at && $e->user) {
                $reactivated++;
                \App\Services\Notifier::user($e->user, 'card_active', 'Your FitAccess card is active',
                    "{$company->name} has paid for your FitAccess membership. Your card (same number as before) works until "
                    . ($e->fresh()->paid_until?->format('j M Y') ?? 'the end of the paid period') . '.',
                    ['buttonText' => 'Open FitAccess', 'buttonUrl' => config('app.frontend_url') . '/employee/cards']);
            } elseif ($e->user) {
                \App\Services\Notifier::user($e->user, 'payment_done', 'Your membership is paid',
                    "{$company->name} has paid for your FitAccess membership. FitAccess will e-mail you your card number shortly.",
                    null);
            }
        }

        $issued = 0;
        if ($toIssue->isNotEmpty()) {
            $r = \App\Services\CardIssuer::issue($company, $toIssue, true);
            $issued = is_array($r) ? $r['issued'] : 0;
            foreach ($toIssue as $e) {
                if ($e->user) {
                    \App\Services\Notifier::user($e->user, 'payment_done', 'Your FitAccess membership is paid',
                        "{$company->name} has paid for your membership. FitAccess has e-mailed you your personal card number; enter it on the Cards page to start using partner gyms.",
                        ['buttonText' => 'Open FitAccess', 'buttonUrl' => config('app.frontend_url') . '/employee/dashboard']);
                }
            }
        }

        return ['approved' => $approved, 'issued' => $issued, 'reactivated' => $reactivated];
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

        // Back to waiting for payment, respecting the calendar (late fee / closed)
        $inv = $billingPayment->invoice;
        $status = $inv->late_fee_applied_at ? 'overdue' : 'sent';
        if ($inv->closes_on && $inv->closes_on->lt(now()->startOfDay())) {
            $status = 'closed';
        }
        $inv->update(['status' => $status, 'closed_at' => $status === 'closed' ? now() : null]);

        if ($inv->company) {
            \App\Services\Notifier::company($inv->company, 'payment_rejected', "Payment for invoice {$inv->invoice_number} was not accepted",
                "FitAccess could not verify your payment: {$request->notes}"
                . ($status === 'closed'
                    ? ' The payment period for this invoice has ended; you can request an exception (negotiation) on the Billing page.'
                    : ' Please submit a correct receipt before ' . ($inv->closes_on?->format('j M Y') ?? 'the deadline') . '.'),
                ['buttonText' => 'Open billing', 'buttonUrl' => config('app.frontend_url') . '/hr/billing'],
                $inv->branch_scoped ? $inv->company_branch_id : null);
        }

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

        // Reopen the invoice for a few days so the company can pay
        $days  = max(1, \App\Support\Settings::int('negotiation_extension_days'));
        $until = now()->startOfDay()->addDays($days);
        $billingNegotiation->update(['extended_until' => $until->toDateString()]);
        $inv = $billingNegotiation->invoice;
        $inv->update([
            'status'    => $inv->late_fee_applied_at ? 'overdue' : 'sent',
            'closes_on' => $until->toDateString(),
            'closed_at' => null,
        ]);

        if ($inv->company) {
            \App\Services\Notifier::company($inv->company, 'negotiation_approved', "Exception approved for invoice {$inv->invoice_number}",
                'FitAccess approved your request. You can pay this invoice until ' . $until->format('j M Y') . '.'
                . ($request->admin_notes ? " Note from FitAccess: {$request->admin_notes}" : ''),
                ['buttonText' => 'Pay invoice', 'buttonUrl' => config('app.frontend_url') . '/hr/billing'],
                $inv->branch_scoped ? $inv->company_branch_id : null);
        }

        return response()->json(['message' => 'Negotiation approved. The company can pay until ' . $until->format('j M Y') . '.']);
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

        $inv = $billingNegotiation->invoice;
        if ($inv?->company) {
            \App\Services\Notifier::company($inv->company, 'negotiation_rejected', "Exception request for invoice {$inv->invoice_number} was not approved",
                "FitAccess reviewed your request and could not approve it: {$request->admin_notes} "
                . ($inv->status === 'closed'
                    ? 'This invoice stays closed. Your employees can be paid for in the next payment window.'
                    : 'Please pay before ' . ($inv->closes_on?->format('j M Y') ?? 'the deadline') . '.'),
                ['buttonText' => 'Open billing', 'buttonUrl' => config('app.frontend_url') . '/hr/billing'],
                $inv->branch_scoped ? $inv->company_branch_id : null);
        }

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
            'period_months'  => (int) ($inv->period_months ?: 1),
            'period_start'   => $inv->period_start?->toDateString(),
            'period_end'     => $inv->period_end?->toDateString(),
            'covered_count'  => $inv->coveredEmployees()->count(),
            'total_amount'   => $inv->total_amount,
            'late_fee_amount' => (float) $inv->late_fee_amount,
            'due_date'       => $inv->due_date?->toDateString(),
            'closes_on'      => $inv->closes_on?->toDateString(),
            'closed_at'      => $inv->closed_at?->toDateTimeString(),
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
                'months'         => (int) ($item->months ?: 1),
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
