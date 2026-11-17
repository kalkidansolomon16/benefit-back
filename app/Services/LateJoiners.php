<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Support\BillingCalendar;
use Illuminate\Support\Carbon;

/**
 * "Employees added after the 20th become active the next working day, and the
 *  extra days are charged on the next invoice."
 *
 * schedule()  – at import: an employee added after the staff-list cut-off gets
 *               activate_on = next working day.
 * activateDue() – daily: activates them (account, card to the company), gives
 *               access until the end of the month that was already invoiced
 *               without them, and marks prorate_from so the next invoice adds a
 *               prorated "extra days" line.
 */
class LateJoiners
{
    public static function schedule(Employee $e, ?Carbon $now = null): bool
    {
        $now ??= now();
        if (!BillingCalendar::isAfterCutoff($now)) {
            return false;            // before the cut-off: on next month's invoice as usual
        }
        if ($e->hasPaidAccess() || $e->admin_approval_status === 'approved') {
            return false;            // already active
        }
        $e->update(['activate_on' => BillingCalendar::nextWorkingDay($now)->toDateString()]);

        return true;
    }

    /** @return int number of employees activated */
    public static function activateDue(?Carbon $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();

        $due = Employee::with('user', 'company')
            ->whereNotNull('activate_on')
            ->whereDate('activate_on', '<=', $today->toDateString())
            ->where('registration_status', 'approved')
            ->get();

        $byCompany = [];
        foreach ($due as $e) {
            if ($e->is_banned) {
                continue;
            }
            $activeFrom = $e->activate_on->copy();
            // Added in month X after the cut-off → X+1 was already invoiced without them:
            // free access until the end of X+1, extra days billed on the X+2 invoice.
            $addedMonth = $activeFrom->copy()->subDay()->startOfMonth();
            $until = $addedMonth->copy()->addMonthNoOverflow()->endOfMonth()->startOfDay();
            if ($e->paid_until && $e->paid_until->gt($until)) {
                $until = $e->paid_until->copy();
            }

            $e->update([
                'admin_approval_status' => 'approved',
                'is_enrolled'           => true,
                'enrolled_at'           => $e->enrolled_at ?? $activeFrom,
                'payment_status'        => 'paid',
                'paid_until'            => $until->toDateString(),
                'prorate_from'          => $activeFrom->toDateString(),
                'activate_on'           => null,
            ]);
            $e->user?->update(['is_active' => true]);
            MembershipService::provisionForEmployee($e, MembershipService::tierFromLevel($e->level), $until);
            AuditLog::record('updated', $e, ['admin_approval_status' => 'pending'], ['late_joiner_active_from' => $activeFrom->toDateString()]);

            if ($e->user) {
                Notifier::user($e->user, 'late_joiner_active', 'Your FitAccess membership is active',
                    'Your membership is active from ' . $activeFrom->format('j M Y') . '. FitAccess has e-mailed you your card number; enter it on the Cards page to start using partner gyms.',
                    ['buttonText' => 'Open FitAccess', 'buttonUrl' => config('app.frontend_url') . '/employee/dashboard']);
            }
            $byCompany[$e->company_id][] = $e->fresh('user');
        }

        foreach ($byCompany as $list) {
            $company = $list[0]->company ?? $list[0]->company()->first();
            if ($company) {
                CardIssuer::issue($company, collect($list));
                Notifier::admins('late_joiners_active', count($list) . " late addition(s) activated for {$company->name}",
                    'Added after the staff-list cut-off and activated today. Their extra days are charged, prorated, on the next invoice.',
                    ['company_id' => $company->id]);
            }
        }

        return count($due);
    }
}
