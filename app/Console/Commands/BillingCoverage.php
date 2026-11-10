<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Membership;
use App\Services\BillingMailer;
use App\Support\BillingPeriod;
use Illuminate\Console\Command;

/**
 * Daily billing housekeeping.
 *
 * Access itself is checked live against employees.paid_until at every
 * check-in, so nothing depends on this command running on time. It only:
 *  1. marks employees whose paid period (plus grace days) has ended as unpaid
 *     and their gym memberships as expired, so lists and dashboards are right;
 *  2. emails each company, and the FitAccess team, a reminder N days before a
 *     paid period ends (config fitaccess.billing_reminder_days, default 7).
 */
class BillingCoverage extends Command
{
    protected $signature = 'billing:coverage {--no-mail : Update statuses only, send no reminders}';

    protected $description = 'Expire unpaid FitAccess access and send renewal reminders before paid periods end';

    public function handle(): int
    {
        $lastCoveredDay = now()->startOfDay()->subDays(BillingPeriod::graceDays() + 1);

        $expiredEmployees = Employee::where('payment_status', 'paid')
            ->whereNotNull('paid_until')
            ->whereDate('paid_until', '<=', $lastCoveredDay)
            ->update(['payment_status' => 'unpaid']);

        $expiredMemberships = Membership::where('status', 'active')
            ->whereNotNull('employee_id')
            ->whereDate('end_date', '<=', $lastCoveredDay)
            ->update(['status' => 'expired']);

        $this->info("Access ended: {$expiredEmployees} employee(s), {$expiredMemberships} membership(s) marked expired.");

        if ($this->option('no-mail')) {
            return self::SUCCESS;
        }

        $remindOn = now()->startOfDay()->addDays(max(1, (int) config('fitaccess.billing_reminder_days', 7)));

        $rows = Employee::where('registration_status', 'approved')
            ->where('is_enrolled', true)
            ->whereDate('paid_until', $remindOn->toDateString())
            ->selectRaw('company_id, count(*) as n')
            ->groupBy('company_id')
            ->get()
            ->map(fn($r) => ($c = Company::find($r->company_id)) && $c->is_active
                ? ['company' => $c, 'until' => $remindOn->copy(), 'count' => (int) $r->n]
                : null)
            ->filter()
            ->values()
            ->all();

        $sent = BillingMailer::renewalReminders($rows);
        $this->info('Renewal reminders: ' . count($rows) . " compan(ies), {$sent} email(s) sent.");

        return self::SUCCESS;
    }
}
