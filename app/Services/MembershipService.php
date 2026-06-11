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

        $plan = MembershipPlan::where('tier', $tier)
            ->where('is_active', true)
            ->first();

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
     * Also sets payment_status = 'paid' on each employee.
     *
     * @param  int  $companyId
     * @return array{employees: int, memberships: int}
     */
    public static function provisionForCompany(int $companyId): array
    {
        $employees = Employee::where('company_id', $companyId)
            ->where('registration_status', 'approved')
            ->where('is_enrolled', true)
            ->where('payment_status', 'unpaid')
            ->get();

        $totalMemberships = 0;

        foreach ($employees as $employee) {
            $tier = self::tierFromLevel($employee->level);
            $totalMemberships += self::provisionForEmployee($employee, $tier);

            // Mark employee as paid
            $employee->update(['payment_status' => 'paid']);
        }

        return [
            'employees'   => $employees->count(),
            'memberships' => $totalMemberships,
        ];
    }
}
