<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Coverage periods for company invoices.
 *
 * An invoice pays for 1, 3, 6 or 12 months starting on a chosen day; the
 * covered employees may use FitAccess until the last day of that period
 * (plus an optional grace period), after which access stops until the next
 * invoice is paid.
 */
class BillingPeriod
{
    /** Allowed payment cycles in months */
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
        return max(0, (int) config('fitaccess.billing_grace_days', 0));
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
     * Where a company's next invoice should start: the day after the latest
     * coverage its enrolled employees already have, or today if that is past.
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

        $latest = $q->max('paid_until');
        $today  = now()->startOfDay();
        if (!$latest) {
            return $today;
        }
        $next = Carbon::parse($latest)->addDay()->startOfDay();

        return $next->gt($today) ? $next : $today;
    }
}
