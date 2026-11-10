<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Gym;
use App\Models\Membership;
use App\Models\MembershipPlan;

class MembershipService
{
    /**
     * Map employee level → plan tier.
     */
    public static function tierFromLevel(?string $level): string
    {
        return \App\Support\Tier::forLevel($level);
    }

    /**
     * Gym tiers that the given plan tier may access.
     * Higher tiers include all lower-tier gyms.
     */
    public static function allowedGymTiers(string $planTier): array
    {
        return \App\Support\Tier::gymTiersFor($planTier);
    }

    /**
     * Find the active plan for a canonical tier ('basic' | 'basic_plus' | 'platinum').
     *
     * Plan tiers are entered by admins and often look like "Fit Basic" / "fit_premium"
     * rather than the canonical key, so match loosely: exact, then suffix, then the
     * platinum/premium synonym, and finally the cheapest active plan so a membership
     * can always be created (plan_id is required).
     */
    public static function resolvePlan(string $tier, bool $fallbackToCheapest = true): ?MembershipPlan
    {
        $plans = MembershipPlan::where('is_active', true)->orderBy('monthly_fee_etb')->get()
            ->map(function (MembershipPlan $p) {
                $p->setAttribute('norm_tier', trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $p->tier)), '_'));
                return $p;
            });

        $find = function (string $t) use ($plans) {
            return $plans->first(fn($p) => $p->norm_tier === $t)
                ?? $plans->first(fn($p) => str_ends_with($p->norm_tier, '_' . $t));
        };

        $plan = $find($tier);
        if (!$plan && in_array($tier, ['platinum', 'premium'], true)) {
            $plan = $find($tier === 'platinum' ? 'premium' : 'platinum');
        }

        if (!$plan && $fallbackToCheapest) {
            $plan = $plans->first();
            if ($plan) {
                \Illuminate\Support\Facades\Log::warning("No active plan matches tier '{$tier}'; using '{$plan->name}'.");
            }
        }

        return $plan;
    }

    /**
     * Create (or re-activate) Membership rows for every active gym the
     * employee is entitled to based on their plan tier.
     *
     * Called ONLY after the company's invoice has been verified/paid.
     *
     * @param  Employee  $employee  A fully-loaded Employee instance.
     * @param  string    $tier      Plan tier: 'basic' | 'basic_plus' | 'platinum'
     * @param  \Illuminate\Support\Carbon|null  $until  Last day the memberships run
     *         (the employee's paid_until). Existing memberships are moved to this date too.
     * @return int  Number of newly-created membership rows.
     */
    public static function provisionForEmployee(Employee $employee, string $tier, ?\Illuminate\Support\Carbon $until = null): int
    {
        $allowedTiers = self::allowedGymTiers($tier);

        $plan = self::resolvePlan($tier);

        $gyms = Gym::whereIn('tier', $allowedTiers)
            ->where('is_active', true)
            ->get();

        $created = 0;
        $endDate  = ($until ?? $employee->paid_until ?? now()->addYear())->toDateString();

        foreach ($gyms as $gym) {
            // Restore any soft-deleted row for this employee+gym pair first
            $existing = Membership::withTrashed()
                ->where('employee_id', $employee->id)
                ->where('gym_id', $gym->id)
                ->first();

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                    $existing->update([
                        'plan_id'    => $plan?->id,
                        'status'     => 'active',
                        'start_date' => now()->toDateString(),
                        'end_date'   => $endDate,
                    ]);
                    $gym->increment('current_members');
                    $created++;
                } elseif ($until && $existing->status !== 'suspended' && $existing->status !== 'cancelled') {
                    // A new paid period: run the membership to the new end date
                    $existing->update(['status' => 'active', 'end_date' => $endDate, 'plan_id' => $plan?->id ?? $existing->plan_id]);
                }
            } else {
                Membership::create([
                    'employee_id' => $employee->id,
                    'gym_id'      => $gym->id,
                    'plan_id'     => $plan?->id,
                    'status'      => 'active',
                    'start_date'  => now()->toDateString(),
                    'end_date'    => $endDate,
                ]);
                $gym->increment('current_members');
                $created++;
            }
        }

        return $created;
    }

    /**
     * Settle a paid invoice: every employee it covers gets access until the end
     * of the invoice's coverage period (paid_until), and their gym memberships
     * are created or moved to that date.
     *
     * Invoices list their employees (billing_invoice_employees). Invoices from
     * before that list existed fall back to "the employees the invoice was for"
     * by company / branch / single employee.
     *
     * @return array{employees: int, memberships: int, paid_until: string|null}
     */
    public static function provisionForInvoice(\App\Models\BillingInvoice $invoice): array
    {
        $until = $invoice->period_end
            ? $invoice->period_end->copy()
            : now()->addMonthsNoOverflow(max(1, (int) ($invoice->period_months ?: 1)))->subDay();

        $employees = $invoice->coveredEmployees()->get();

        if ($employees->isEmpty()) {
            $employees = Employee::where('company_id', $invoice->company_id)
                ->where('registration_status', 'approved')
                ->where('is_enrolled', true)
                ->when($invoice->employee_id, fn($q) => $q->where('id', $invoice->employee_id))
                ->when($invoice->branch_scoped && !$invoice->employee_id, fn($q) => $invoice->company_branch_id
                    ? $q->where('company_branch_id', $invoice->company_branch_id)
                    : $q->whereNull('company_branch_id'))
                ->where(fn($q) => $q->whereNull('paid_until')->orWhereDate('paid_until', '<', $until))
                ->get();
        }

        $totalMemberships = 0;

        foreach ($employees as $employee) {
            // Never shorten coverage an employee already has
            $newUntil = $employee->paid_until && $employee->paid_until->gt($until)
                ? $employee->paid_until->copy()
                : $until->copy();

            $employee->update([
                'payment_status' => 'paid',
                'paid_period'    => $invoice->billing_period,
                'paid_until'     => $newUntil->toDateString(),
            ]);

            $tier = self::tierFromLevel($employee->level);
            $totalMemberships += self::provisionForEmployee($employee, $tier, $newUntil);
        }

        return [
            'employees'   => $employees->count(),
            'memberships' => $totalMemberships,
            'paid_until'  => $until->toDateString(),
        ];
    }
}
