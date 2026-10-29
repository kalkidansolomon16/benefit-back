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
        return match ($level) {
            'chief'    => 'platinum',
            'director' => 'basic_plus',
            default    => 'basic',
        };
    }

    /**
     * Gym tiers that the given plan tier may access.
     * Higher tiers include all lower-tier gyms.
     */
    public static function allowedGymTiers(string $planTier): array
    {
        return match ($planTier) {
            'platinum'   => ['basic', 'basic_plus', 'platinum'],
            'basic_plus' => ['basic', 'basic_plus'],
            default      => ['basic'],
        };
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
     * @return int  Number of newly-created membership rows.
     */
    public static function provisionForEmployee(Employee $employee, string $tier): int
    {
        $allowedTiers = self::allowedGymTiers($tier);

        $plan = self::resolvePlan($tier);

        $gyms = Gym::whereIn('tier', $allowedTiers)
            ->where('is_active', true)
            ->get();

        $created = 0;
        $endDate  = now()->addYear()->toDateString();

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
                }
                // Already exists and not deleted → leave untouched
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
     * Provision memberships for every enrolled+approved employee of a company
     * after their invoice payment is verified.
     *
     * Sets payment_status = 'paid' and stamps paid_period with the billing
     * period just settled, so the next billing cycle's invoice generation can
     * tell "paid for last period" apart from "paid for this period".
     *
     * @param  int  $companyId
     * @param  string|null  $billingPeriod  The billing_period of the invoice just paid.
     * @return array{employees: int, memberships: int}
     */
    /** Settle exactly the employees an invoice covers */
    public static function provisionForInvoice(\App\Models\BillingInvoice $invoice): array
    {
        return self::provisionForCompany(
            $invoice->company_id,
            $invoice->billing_period,
            $invoice->employee_id,
            (bool) $invoice->branch_scoped,
            $invoice->company_branch_id
        );
    }

    public static function provisionForCompany(
        int $companyId,
        ?string $billingPeriod = null,
        ?int $employeeId = null,
        bool $branchScoped = false,
        ?int $branchId = null
    ): array {
        $employees = Employee::where('company_id', $companyId)
            ->where('registration_status', 'approved')
            ->where('is_enrolled', true)
            ->where(function ($q) use ($billingPeriod) {
                $q->whereNull('paid_period')
                  ->orWhere('paid_period', '!=', $billingPeriod);
            })
            ->when($employeeId, fn($q) => $q->where('id', $employeeId))
            // A per-branch invoice settles only that branch's employees (null = head office)
            ->when($branchScoped && !$employeeId, fn($q) => $branchId
                ? $q->where('company_branch_id', $branchId)
                : $q->whereNull('company_branch_id'))
            ->get();

        $totalMemberships = 0;

        foreach ($employees as $employee) {
            $tier = self::tierFromLevel($employee->level);
            $totalMemberships += self::provisionForEmployee($employee, $tier);

            // Mark employee as paid for this specific billing period
            $employee->update([
                'payment_status' => 'paid',
                'paid_period'    => $billingPeriod,
            ]);
        }

        return [
            'employees'   => $employees->count(),
            'memberships' => $totalMemberships,
        ];
    }
}
