<?php

namespace App\Services;

use App\Models\Checkin;
use App\Models\Employee;
use App\Models\Gym;
use App\Models\PartnerPayout;
use App\Models\PartnerStatement;
use App\Support\BillingCalendar;
use App\Support\Settings;
use App\Support\Tier;
use Illuminate\Support\Carbon;

/**
 * Monthly statements for partner gyms: how many FitAccess visits a gym had in a
 * month, per member plan tier, and what FitAccess owes for them.
 *
 * Each line = visits by members of that tier × the gym's rate for that tier
 * (gyms.visit_rates, falling back to gyms.per_visit_rate).
 *
 * The totals are mirrored into partner_payouts (read by the partner mobile app)
 * so the app's payout history stays in step: pending until paid, then processed.
 */
class PartnerStatements
{
    public const TIERS = ['basic', 'basic_plus', 'premium'];

    /** tier => rate per visit */
    public static function rates(Gym $gym): array
    {
        $base = (float) ($gym->per_visit_rate ?? 0);
        $set = is_array($gym->visit_rates) ? $gym->visit_rates : [];
        $out = [];
        foreach (array_merge(self::TIERS, ['other']) as $t) {
            $out[$t] = isset($set[$t]) && $set[$t] !== '' ? (float) $set[$t] : $base;
        }

        return $out;
    }

    public static function tierLabel(string $tier): string
    {
        if ($tier === 'other') {
            return 'Other members';
        }

        return MembershipService::resolvePlan($tier, false)?->name ?? ucwords(str_replace('_', ' ', $tier));
    }

    /** Count a month of visits for one gym, grouped by member tier. */
    public static function build(Gym $gym, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $rates = self::rates($gym);

        $checkins = Checkin::where('gym_id', $gym->id)
            ->whereBetween('checked_in_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->get(['id', 'employee_id', 'user_id']);
        $levels = Employee::withTrashed()->whereIn('id', $checkins->pluck('employee_id')->filter()->unique())->pluck('level', 'id');

        $groups = [];
        foreach ($checkins as $c) {
            $tier = $c->employee_id && isset($levels[$c->employee_id])
                ? Tier::canonical(Tier::forLevel($levels[$c->employee_id]))
                : 'other';
            if (!in_array($tier, self::TIERS, true)) {
                $tier = 'other';
            }
            $groups[$tier]['visits'] = ($groups[$tier]['visits'] ?? 0) + 1;
            $groups[$tier]['members'][$c->employee_id ? "e{$c->employee_id}" : "u{$c->user_id}"] = true;
        }

        $lines = [];
        $total = 0.0;
        $members = [];
        foreach (array_merge(self::TIERS, ['other']) as $t) {
            if (empty($groups[$t])) continue;
            $visits = $groups[$t]['visits'];
            $subtotal = round($visits * $rates[$t], 2);
            $total += $subtotal;
            $members += $groups[$t]['members'];
            $lines[] = [
                'tier'     => $t,
                'label'    => self::tierLabel($t),
                'visits'   => $visits,
                'members'  => count($groups[$t]['members']),
                'rate'     => $rates[$t],
                'subtotal' => $subtotal,
            ];
        }

        return [
            'period'         => $start->format('Y-m'),
            'period_start'   => $start->toDateString(),
            'period_end'     => $end->toDateString(),
            'lines'          => $lines,
            'total_visits'   => $checkins->count(),
            'unique_members' => count($members),
            'total_amount'   => round($total, 2),
        ];
    }

    /**
     * Create or refresh draft statements for every gym that had visits in the
     * month. Statements already invoiced or paid are never changed.
     *
     * @return int statements created or refreshed
     */
    public static function generate(Carbon $month, ?int $gymId = null): int
    {
        $start = $month->copy()->startOfMonth();
        $gymIds = Checkin::whereBetween('checked_in_at', [$start->copy()->startOfDay(), $start->copy()->endOfMonth()->endOfDay()])
            ->when($gymId, fn ($q) => $q->where('gym_id', $gymId))
            ->distinct()->pluck('gym_id');

        $n = 0;
        foreach (Gym::whereIn('id', $gymIds)->get() as $gym) {
            $existing = PartnerStatement::where('gym_id', $gym->id)->where('period', $start->format('Y-m'))->first();
            if ($existing && !$existing->isEditable()) {
                continue;
            }
            $data = self::build($gym, $start);
            $statement = PartnerStatement::updateOrCreate(
                ['gym_id' => $gym->id, 'period' => $data['period']],
                array_merge($data, ['status' => $existing->status ?? 'draft'])
            );
            self::mirrorPayout($statement);
            $n++;
        }

        return $n;
    }

    /** Recalculate one statement (e.g. after changing the gym's rates) */
    public static function refresh(PartnerStatement $s): PartnerStatement
    {
        $s->update(self::build($s->gym, $s->period_start));
        self::mirrorPayout($s->fresh());

        return $s->fresh();
    }

    /** Send the statement to the gym: invoice expected within 3 working days */
    public static function send(PartnerStatement $s): void
    {
        $due = now()->startOfDay();
        for ($i = 0; $i < 3; $i++) {
            $due = BillingCalendar::nextWorkingDay($due);
        }
        $s->update(['status' => 'sent', 'sent_at' => now(), 'invoice_due_on' => $due->toDateString()]);
        $s->loadMissing('gym');
        $month = $s->period_start->format('F Y');
        $amount = 'ETB ' . number_format((float) $s->total_amount, 2);

        $details = ['Period' => $month, 'Visits' => (string) $s->total_visits, 'Members' => (string) $s->unique_members];
        foreach ($s->lines ?? [] as $l) {
            $details[$l['label']] = "{$l['visits']} visits × ETB " . number_format((float) $l['rate'], 2) . ' = ETB ' . number_format((float) $l['subtotal'], 2);
        }
        $details['Amount due to you'] = $amount;

        Notifier::gym($s->gym, 'partner_statement', "FitAccess statement for {$month}: {$amount}",
            "Your FitAccess statement for {$month} is ready: {$s->total_visits} visits, {$amount}. Please upload your VAT or TOT invoice for this amount by "
            . $due->format('j M Y') . '. FitAccess pays by the ' . BillingCalendar::ord(Settings::int('partner_payment_day')) . '.',
            [
                'subject'    => "FitAccess statement for {$month} — {$amount}",
                'heading'    => "Your statement for {$month}",
                'highlight'  => "Amount due to you: {$amount}",
                'paragraphs' => [
                    "Here is the statement of FitAccess member visits to {$s->gym->name} in {$month}, by member plan.",
                    'Please check it and upload your VAT or TOT invoice for this amount in the FitAccess partner portal (Statements) by ' . $due->format('j M Y') . '.',
                ],
                'details'    => $details,
                'buttonText' => 'Open statements',
                'buttonUrl'  => config('app.frontend_url') . '/partner/statements',
                'closing'    => 'If something looks wrong, add a note when you upload your invoice and FitAccess will review it.',
            ]);
    }

    /** Keep the mobile app's payout history in step with the statement */
    public static function mirrorPayout(PartnerStatement $s): void
    {
        try {
            $rate = $s->total_visits ? round((float) $s->total_amount / $s->total_visits, 2) : 0;
            PartnerPayout::updateOrCreate(
                ['gym_id' => $s->gym_id, 'period' => $s->period],
                [
                    'period_start'   => $s->period_start->toDateString(),
                    'period_end'     => $s->period_end->toDateString(),
                    'total_checkins' => $s->total_visits,
                    'per_visit_rate' => $rate,
                    'total_amount'   => $s->total_amount,
                    'status'         => $s->status === 'paid' ? 'processed' : 'pending',
                    'paid_at'        => $s->paid_at,
                    'notes'          => $s->payment_reference ? "Payment reference: {$s->payment_reference}" : null,
                ]
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Partner payout mirror failed: ' . $e->getMessage());
        }
    }

    public static function format(PartnerStatement $s, bool $admin = false): array
    {
        $s->loadMissing('gym:id,name,branch_name,tier,contact_email,per_visit_rate,visit_rates');

        return [
            'id'             => $s->id,
            'gym'            => $s->gym ? ['id' => $s->gym->id, 'name' => trim($s->gym->name . ($s->gym->branch_name ? " — {$s->gym->branch_name}" : '')), 'tier' => $s->gym->tier] : null,
            'period'         => $s->period,
            'period_label'   => $s->period_start->format('F Y'),
            'period_start'   => $s->period_start->toDateString(),
            'period_end'     => $s->period_end->toDateString(),
            'total_visits'   => $s->total_visits,
            'unique_members' => $s->unique_members,
            'total_amount'   => (float) $s->total_amount,
            'lines'          => $s->lines ?? [],
            'status'         => $s->status,
            'sent_at'        => $s->sent_at?->toIso8601String(),
            'invoice_due_on' => $s->invoice_due_on?->toDateString(),
            'invoice'        => $s->partner_invoice_path ? [
                'number'      => $s->partner_invoice_number,
                'type'        => $s->partner_invoice_type,
                'amount'      => $s->partner_invoice_amount !== null ? (float) $s->partner_invoice_amount : null,
                'note'        => $s->partner_note,
                'file_name'   => $s->partner_invoice_name,
                'uploaded_at' => $s->invoiced_at?->toIso8601String(),
            ] : null,
            'payment'        => $s->paid_at ? [
                'reference' => $s->payment_reference,
                'paid_at'   => $s->paid_at->toIso8601String(),
                'file_name' => $s->receipt_name,
                'note'      => $s->admin_note,
            ] : null,
            'rates'          => $admin && $s->gym ? self::rates($s->gym) : null,
            'editable'       => $s->isEditable(),
        ];
    }
}
