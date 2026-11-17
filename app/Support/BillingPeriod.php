<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Coverage periods for company invoices.
 *
 * An invoice pays for 1, 3, 6 or 12 months starting on the 1st of a month; the
 * covered employees may use FitAccess until the last day of that period
 * (plus an optional grace period), after which access stops until the next
 * invoice is paid.
 */
class BillingPeriod
{
    /** Allowed payment cycles in months: monthly (default), 3, 6 or 12 months */
    public const CYCLES = [1, 3, 6, 12];

    public static function label(int $months): string
    {
        return match ($months) {
            1       => 'Monthly (1 month)',
            3       => 'Quarterly (3 months)',
            6       => 'Every 6 months',
            12      => 'Yearly (12 months)',
            default => "{$months} months",
        };
    }

    /** Last covered day: start + N months − 1 day (1 Nov + 3 months → 31 Jan) */
    public static function endFor(Carbon $start, int $months): Carbon
    {
        return $start->copy()->addMonthsNoOverflow($months)->subDay()->startOfDay();
    }

    /** "1 Nov 2026 – 31 Jan 2027" */
    public static function rangeText(Carbon $start, Carbon $end): string
    {
        return $start->format('j M Y') . ' – ' . $end->format('j M Y');
    }

    /** Days of access still allowed after paid_until before check-in is refused */
    public static function graceDays(): int
    {
        // Paid periods end on the last day of a month; the grace period runs to the
        // 7th of the next month (Admin → Rules), so access pauses from the 8th.
        return max(0, (int) config('fitaccess.billing_grace_days', 0), Settings::int('grace_end_day'));
    }

    /** Is the employee's access paid for today (including the grace period)? */
    public static function covers(?Carbon $paidUntil, ?Carbon $on = null): bool
    {
        if (!$paidUntil) {
            return false;
        }
        $on = ($on ?? now())->copy()->startOfDay();

        return $paidUntil->copy()->addDays(self::graceDays())->gte($on);
    }

    /**
     * Where a company's next invoice should start: the first day an enrolled
     * employee is not covered. If anyone is unpaid or already expired that is
     * today, so employees who are still paid up are left off the invoice
     * (they are billed only once their own coverage runs out). When everybody
     * is paid ahead, it is the day after the earliest coverage ends.
     */
    public static function suggestedStart(int $companyId, ?string $branch = null): Carbon
    {
        $q = Employee::where('company_id', $companyId)
            ->where('registration_status', 'approved')
            ->where('is_enrolled', true);
        if ($branch === 'main') {
            $q->whereNull('company_branch_id');
        } elseif ($branch !== null && ctype_digit($branch)) {
            $q->where('company_branch_id', (int) $branch);
        }

        $today = now()->startOfDay();

        // Someone has never been paid for → bill from today
        if ((clone $q)->whereNull('paid_until')->exists()) {
            return $today;
        }
        $earliest = $q->min('paid_until');
        if (!$earliest) {
            return $today;
        }
        $next = Carbon::parse($earliest)->addDay()->startOfDay();

        return $next->gt($today) ? $next : $today;
    }
}
