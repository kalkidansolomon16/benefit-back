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

        $ending = Employee::with('user', 'company')
            ->where('payment_status', 'paid')
            ->whereNotNull('paid_until')
            ->whereDate('paid_until', '<=', $lastCoveredDay)
            ->get();

        $expiredEmployees = Employee::whereIn('id', $ending->pluck('id'))->update(['payment_status' => 'unpaid']);

        // The card is paused (not replaced): the same number works again once the company pays
        if (!$this->option('no-mail')) {
            foreach ($ending as $e) {
                if ($e->user && $e->account_created_at) {
                    \App\Services\Notifier::user($e->user, 'card_paused', 'Your FitAccess card is paused',
                        'Your company\'s paid period ended on ' . $e->paid_until->format('j M Y') . '. Your card is paused until '
                        . ($e->company?->name ?? 'your company') . ' pays for the next period. Your card number stays the same; you do not need a new one.',
                        ['buttonText' => 'Open FitAccess', 'buttonUrl' => config('app.frontend_url') . '/employee/cards']);
                }
            }
            foreach ($ending->groupBy('company_id') as $group) {
                $c = $group->first()->company;
                if ($c) {
                    \App\Services\Notifier::company($c, 'cards_paused', $group->count() . ' employee card(s) paused',
                        'The paid period ended for ' . $group->count() . ' employee(s), so their FitAccess cards are paused. '
                        . 'Import your updated employee list and pay the next invoice in the payment window (' . \App\Support\BillingCalendar::windowText(\App\Support\BillingCalendar::nextPeriodStart()) . '). '
                        . 'The same card numbers become active again after payment.',
                        ['buttonText' => 'Open billing', 'buttonUrl' => config('app.frontend_url') . '/hr/billing']);
                }
            }
        }

        $expiredMemberships = Membership::where('status', 'active')
            ->whereNotNull('employee_id')
            ->whereDate('end_date', '<=', $lastCoveredDay)
            ->update(['status' => 'expired']);

        $this->info("Access ended: {$expiredEmployees} employee(s), {$expiredMemberships} membership(s) marked expired.");

        if ($this->option('no-mail')) {
            return self::SUCCESS;
        }

        $remindOn = now()->startOfDay()->addDays(max(1, \App\Support\Settings::int('renewal_reminder_days')));

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
        foreach ($rows as $r) {
            \App\Services\Notifier::company($r['company'], 'renewal_reminder', 'FitAccess paid period ends on ' . $r['until']->format('j M Y'),
                "$r[count] employee(s) are covered until " . $r['until']->format('j M Y') . '. Import your updated employee list and pay the next invoice in the payment window to keep their cards active.',
                null, null, ['link' => '/hr/billing']);
        }
        $this->info('Renewal reminders: ' . count($rows) . " compan(ies), {$sent} email(s) sent.");

        return self::SUCCESS;
    }
}
