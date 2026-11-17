<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The FitAccess monthly operating calendar (all days editable in Admin → Rules).
 *
 * For a service month M (period starting on the 1st of M):
 *   – by the 20th of M-1   HR confirms the staff list (cut-off)
 *   – on the 21st of M-1   draft invoices are created for finance to check
 *   – by the 25th of M-1   finance sends them
 *   – on the 30th of M-1   payment is due, in advance
 *   – 1st / 3rd / 5th of M reminders while unpaid
 *   – 7th of M             grace period ends (no fee by default)
 *   – from the 8th of M    access is paused and the invoice closes
 *                          (FitAccess can approve an exception)
 *
 * Employees added after the cut-off become active the next working day; their
 * extra days are charged on the following invoice.
 */
class BillingCalendar
{
    public static function cutoffDay(): int      { return self::day('staff_list_cutoff_day'); }
    public static function draftDay(): int       { return self::day('invoice_draft_day'); }
    public static function sendDay(): int        { return self::day('invoice_send_day'); }
    public static function dueDay(): int         { return max(1, min(31, Settings::int('payment_due_day'))); }
    public static function graceEndDay(): int    { return self::day('grace_end_day'); }
    public static function lateFeePercent(): float { return max(0, (float) Settings::get('late_fee_percent')); }

    /** Reminder days in the service month: 1st, 3rd, 5th … before the grace period ends */
    public static function reminderDays(): array
    {
        return array_values(array_filter([1, 3, 5], fn ($d) => $d < self::graceEndDay()));
    }

    private static function day(string $key): int
    {
        return max(1, min(28, Settings::int($key)));
    }

    private static function dayIn(Carbon $month, int $day): Carbon
    {
        $m = $month->copy()->startOfMonth();

        return $m->day(min($day, $m->daysInMonth));
    }

    /**
     * Which service month a new invoice is for: the current month while it is
     * still in its grace period (1st–7th), otherwise next month.
     */
    public static function nextPeriodStart(?Carbon $today = null): Carbon
    {
        $today = ($today ?? now())->copy()->startOfDay();

        return $today->day <= self::graceEndDay()
            ? $today->copy()->startOfMonth()
            : $today->copy()->addMonthNoOverflow()->startOfMonth();
    }

    /** The month the automatic drafts on the 21st are for: always next month */
    public static function upcomingPeriodStart(?Carbon $today = null): Carbon
    {
        return ($today ?? now())->copy()->startOfMonth()->addMonthNoOverflow();
    }

    /** Staff-list cut-off for a service month (20th of the month before) */
    public static function staffListDeadline(Carbon $periodStart): Carbon
    {
        return self::dayIn($periodStart->copy()->startOfMonth()->subMonthNoOverflow(), self::cutoffDay());
    }

    /** When invoices for a service month are sent (25th of the month before) */
    public static function sendDate(Carbon $periodStart): Carbon
    {
        return self::dayIn($periodStart->copy()->startOfMonth()->subMonthNoOverflow(), self::sendDay());
    }

    /** Payment due date: the 30th (or last day) of the month before the service month */
    public static function dueDate(Carbon $periodStart): Carbon
    {
        return self::dayIn($periodStart->copy()->startOfMonth()->subMonthNoOverflow(), self::dueDay());
    }

    /** Last day of the grace period; the invoice closes and access pauses after it */
    public static function closesOn(Carbon $periodStart): Carbon
    {
        return self::dayIn($periodStart, self::graceEndDay());
    }

    /** "Due 30 Nov 2026 · grace until 7 Dec 2026" */
    public static function windowText(Carbon $periodStart): string
    {
        return 'due ' . self::dueDate($periodStart)->format('j M Y') . ', grace period until ' . self::closesOn($periodStart)->format('j M Y');
    }

    /** Was this person added after the staff-list cut-off for next month? */
    public static function isAfterCutoff(?Carbon $day = null): bool
    {
        return ($day ?? now())->day > self::cutoffDay();
    }

    /** Next working day (Monday–Friday) */
    public static function nextWorkingDay(?Carbon $day = null): Carbon
    {
        $d = ($day ?? now())->copy()->startOfDay()->addDay();
        while ($d->isWeekend()) {
            $d->addDay();
        }

        return $d;
    }

    /** Human summary of the rules, for emails and the HR portal */
    public static function rulesText(): array
    {
        $fee = self::lateFeePercent();

        return [
            'Confirm your staff list by the ' . self::ord(self::cutoffDay()) . ' of each month. The next month\'s invoice is built only from that list.',
            'FitAccess prepares the invoices from the ' . self::ord(self::draftDay()) . ' and sends them by the ' . self::ord(self::sendDay()) . '.',
            'Payment is due on the ' . self::ord(self::dueDay()) . ', in advance of the service month.',
            'Reminders go out on the ' . implode(', ', array_map([self::class, 'ord'], self::reminderDays())) . '. The grace period ends on the ' . self::ord(self::graceEndDay())
                . ($fee > 0 ? ' (a ' . rtrim(rtrim(number_format($fee, 2), '0'), '.') . '% late fee applies after the due date)' : '')
                . ' and staff access is paused from the ' . self::ord(self::graceEndDay() + 1) . '.',
            'Employees added after the ' . self::ord(self::cutoffDay()) . ' become active the next working day; their extra days are charged on the next invoice.',
        ];
    }

    public static function lateFeeFor(float $amount): float
    {
        return round($amount * self::lateFeePercent() / 100, 2);
    }

    /** 1 → "1st", 22 → "22nd" */
    public static function ord(int $n): string
    {
        $s = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n . $s;
    }

    /**
     * Prorated price for access from $from to $to (inclusive), billed month by
     * month: fee × days used ÷ days in that month.
     */
    public static function prorate(float $monthlyFee, Carbon $from, Carbon $to): float
    {
        $total = 0.0;
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        while ($cursor->lte($end)) {
            $monthEnd = $cursor->copy()->endOfMonth()->startOfDay();
            $last = $monthEnd->lt($end) ? $monthEnd : $end;
            $days = $cursor->diffInDays($last) + 1;
            $total += $monthlyFee * $days / $cursor->daysInMonth;
            $cursor = $last->copy()->addDay();
        }

        return round($total, 2);
    }
}
