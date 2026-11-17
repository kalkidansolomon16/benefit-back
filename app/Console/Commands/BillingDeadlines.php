<?php

namespace App\Console\Commands;

use App\Http\Controllers\AdminBillingController;
use App\Models\BillingInvoice;
use App\Models\Company;
use App\Services\LateJoiners;
use App\Services\Notifier;
use App\Support\BillingCalendar;
use App\Support\BillingPeriod;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Daily: run the FitAccess operating calendar (days editable in Admin → Rules).
 *
 *   17th & 20th   remind companies to confirm their staff list for next month
 *   21st          draft next month's invoices from the staff lists (finance checks them)
 *   25th          remind admins about drafts that are still unsent
 *   after the 30th  unpaid invoices become overdue (late fee only if configured)
 *   1st/3rd/5th   reminders to companies that have not paid
 *   after the 7th close unpaid invoices; access pauses (exceptions can be approved)
 *   every day     activate employees added after the cut-off (next working day)
 *   7th & 10th    remind admins: partner statements / partner payments
 *
 * An invoice with a receipt waiting for verification is never marked late or
 * closed: the company paid in time.
 */
class BillingDeadlines extends Command
{
    protected $signature = 'billing:deadlines {--date= : Run as if today were this date (Y-m-d), for testing}';

    protected $description = 'Run the monthly billing calendar: staff-list reminders, invoice drafts, payment reminders, grace period, late joiners';

    public function handle(): int
    {
        $today = $this->option('date') ? \Illuminate\Support\Carbon::parse($this->option('date'))->startOfDay() : now()->startOfDay();
        $day = $today->day;
        $log = [];

        // ── Late joiners: active the next working day ─────────────
        $log[] = 'late joiners activated: ' . LateJoiners::activateDue($today);

        // ── Staff-list reminders (3 days before and on the cut-off) ─
        $cutoff = BillingCalendar::cutoffDay();
        if ($day === $cutoff - 3 || $day === $cutoff) {
            $next = BillingCalendar::upcomingPeriodStart($today);
            $n = 0;
            foreach (Company::where('is_active', true)->get() as $c) {
                Notifier::company($c, 'staff_list_reminder',
                    $day === $cutoff ? 'Today: confirm your staff list for ' . $next->format('F') : 'Confirm your staff list for ' . $next->format('F') . ' by the ' . BillingCalendar::ord($cutoff),
                    'Your ' . $next->format('F Y') . ' invoice is built only from your employee list on the ' . BillingCalendar::ord($cutoff) . '. '
                    . 'Import any new employees who signed the consent form (and update changes) before then. Employees added later become active the next working day and their extra days are charged on the following invoice.',
                    ['buttonText' => 'Open employees', 'buttonUrl' => config('app.frontend_url') . '/hr/employees']);
                $n++;
            }
            $log[] = "staff-list reminders: {$n}";
        }

        // ── 21st: draft next month's invoices ─────────────────────
        if ($day === BillingCalendar::draftDay()) {
            $log[] = 'drafts created: ' . $this->draftInvoices($today);
        }

        // ── 25th: drafts still unsent ─────────────────────────────
        if ($day === BillingCalendar::sendDay()) {
            $next = BillingCalendar::upcomingPeriodStart($today);
            $drafts = BillingInvoice::where('status', 'draft')->whereDate('period_start', $next->toDateString())->count();
            if ($drafts) {
                Notifier::admins('drafts_unsent', "{$drafts} invoice(s) for " . $next->format('F') . ' are still drafts',
                    'Invoices are sent to companies today. Check and send them from Invoices → Send all drafts.', [], true);
            }
            $log[] = "unsent drafts: {$drafts}";
        }

        // ── Unpaid invoices: overdue, reminders, close ───────────
        $fees = $closed = $reminders = $overdue = 0;
        $pct = BillingCalendar::lateFeePercent();
        foreach (BillingInvoice::with('company')->whereIn('status', ['sent', 'overdue'])->get() as $inv) {
            if (!$inv->company) continue;
            $branch = $inv->branch_scoped ? $inv->company_branch_id : null;
            $pending = $inv->payments()->where('status', 'pending')->exists();
            if ($pending) continue;
            $amount = 'ETB ' . number_format((float) $inv->total_amount, 2);
            $link = ['buttonText' => 'Pay invoice', 'buttonUrl' => config('app.frontend_url') . '/hr/billing'];
            $closes = $inv->closes_on;

            // Close after the grace period
            if ($closes && $closes->lt($today)) {
                $inv->update(['status' => 'closed', 'closed_at' => now()]);
                $closed++;
                Notifier::company($inv->company, 'invoice_closed', "Invoice {$inv->invoice_number} is closed: staff access paused",
                    "Invoice {$inv->invoice_number} ({$amount}) was not paid by the end of the grace period (" . $closes->format('j M Y') . '). '
                    . 'The invoice is now closed and your employees\' FitAccess access is paused. They can be included again on next month\'s invoice. If there is an exceptional reason, request an exception on the Billing page; if FitAccess approves it, you can still pay this invoice.',
                    ['buttonText' => 'Open billing', 'buttonUrl' => config('app.frontend_url') . '/hr/billing'], $branch);
                Notifier::admins('invoice_closed', "Invoice {$inv->invoice_number} closed unpaid", "{$inv->company->name}: {$amount} not paid by the end of the grace period.", ['invoice_id' => $inv->id]);
                continue;
            }

            // Past the due date: overdue (fee only if one is configured)
            if ($inv->status === 'sent' && $inv->due_date && $inv->due_date->lt($today)) {
                $update = ['status' => 'overdue'];
                $feeText = '';
                if ($pct > 0 && !$inv->late_fee_applied_at) {
                    $fee = BillingCalendar::lateFeeFor((float) $inv->total_amount);
                    $update += ['late_fee_amount' => $fee, 'late_fee_applied_at' => now(), 'total_amount' => round((float) $inv->total_amount + $fee, 2)];
                    $feeText = ' A late fee of ETB ' . number_format($fee, 2) . ' was added.';
                    $fees++;
                }
                $inv->update($update);
                $overdue++;
                Notifier::company($inv->company, 'invoice_overdue', "Invoice {$inv->invoice_number} is overdue",
                    'The payment was due on ' . $inv->due_date->format('j M Y') . '.' . $feeText . ' Please pay by ' . ($closes?->format('j M Y') ?? 'the end of the grace period')
                    . ' to keep your employees\' access. From the next day access is paused.',
                    $link, $branch);
                continue;
            }

            // Reminders on the 1st, 3rd, 5th of the service month
            if ($inv->period_start && $today->isSameMonth($inv->period_start) && in_array($day, BillingCalendar::reminderDays(), true)
                && $inv->last_reminder !== 'r' . $today->toDateString()) {
                Notifier::company($inv->company, 'invoice_reminder', "Reminder: invoice {$inv->invoice_number} is unpaid",
                    "Please pay {$amount}. The grace period ends on " . ($closes?->format('j M Y') ?? 'the 7th') . '; after that your employees\' access is paused.',
                    $link, $branch);
                $inv->update(['last_reminder' => 'r' . $today->toDateString()]);
                $reminders++;
            }
        }
        $log[] = "overdue: {$overdue}, late fees: {$fees}, reminders: {$reminders}, closed: {$closed}";

        // ── Partner statements: drafts on the 1st, send by the 7th, pay by the 10th ──
        if ($day === 1) {
            $last = $today->copy()->subMonthNoOverflow()->startOfMonth();
            $n = \App\Services\PartnerStatements::generate($last);
            if ($n) {
                Notifier::admins('partner_statements_ready', "{$n} partner statement(s) for " . $last->format('F Y') . ' are ready',
                    'Check them and send them to the gyms by the ' . BillingCalendar::ord(Settings::int('partner_statement_day')) . ' (Partner statements → Send all).', [], true);
            }
            $log[] = "partner statements drafted: {$n}";
        }
        if ($day === Settings::int('partner_statement_day')) {
            $drafts = \App\Models\PartnerStatement::where('status', 'draft')->count();
            if ($drafts) {
                Notifier::admins('partner_statements_unsent', "{$drafts} partner statement(s) still unsent",
                    'Partner statements are due to the gyms today. Send them from Partner statements → Send all.', [], true);
            }
            $log[] = "unsent partner statements: {$drafts}";
        }
        if ($day === Settings::int('partner_payment_day')) {
            $unpaid = \App\Models\PartnerStatement::whereIn('status', ['sent', 'invoiced'])->get();
            if ($unpaid->count()) {
                Notifier::admins('partner_payments_due', $unpaid->count() . ' partner payment(s) due today',
                    $unpaid->where('status', 'invoiced')->count() . ' gym(s) have sent their invoice; ' . $unpaid->where('status', 'sent')->count()
                    . ' have not yet. Total ETB ' . number_format((float) $unpaid->sum('total_amount'), 2) . '. Pay them and upload the receipts in Partner statements.', [], true);
            }
            $log[] = 'partner payments due: ' . $unpaid->count();
        }
        $this->info(implode(' | ', $log));

        return self::SUCCESS;
    }

    /** Draft next month's invoice for every active company from its current staff list */
    private function draftInvoices(\Illuminate\Support\Carbon $today): int
    {
        $start = BillingCalendar::upcomingPeriodStart($today);
        $billing = app(AdminBillingController::class);
        $created = 0;
        $companies = [];

        foreach (Company::where('is_active', true)->get() as $company) {
            $months = in_array((int) $company->billing_cycle_months, BillingPeriod::CYCLES, true) ? (int) $company->billing_cycle_months : 1;
            try {
                $res = $billing->generate(new Request([
                    'company_id'    => $company->id,
                    'period_start'  => $start->toDateString(),
                    'period_months' => $months,
                    'notes'         => 'Prepared automatically from the staff list on the ' . BillingCalendar::ord(BillingCalendar::cutoffDay()) . '.',
                ]));
                if ($res->getStatusCode() === 201) {
                    $n = count($res->getData(true)['invoices'] ?? []);
                    $created += $n;
                    $companies[] = $company->name;
                }
            } catch (\Throwable $e) {
                $this->warn("Draft for {$company->name} failed: " . $e->getMessage());
            }
        }

        if ($created) {
            Notifier::admins('drafts_ready', "{$created} draft invoice(s) for " . $start->format('F Y') . ' are ready to check',
                'Drafts were created from the staff lists for: ' . implode(', ', $companies) . '. Check them and send them by the '
                . BillingCalendar::ord(BillingCalendar::sendDay()) . ' (Invoices → Send all drafts).', [], true);
        }

        return $created;
    }
}
