<?php

namespace App\Services;

use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Models\Company;

class SubscriptionService
{
    public function calculateFees(int $employeeCount, MembershipPlan $plan): array
    {
        $totalAmount    = $employeeCount * $plan->monthly_fee_etb;
        $serviceFee     = $totalAmount * 0.03;
        $absenteeismFee = $totalAmount * 0.01;

        return [
            'total_amount_etb'       => round($totalAmount, 2),
            'service_fee_etb'        => round($serviceFee, 2),
            'absenteeism_fee_etb'    => round($absenteeismFee, 2),
            'fitaccess_revenue_etb'  => round($serviceFee + $absenteeismFee, 2),
        ];
    }

    public function createSubscription(Company $company, MembershipPlan $plan, array $data): Subscription
    {
        $fees = $this->calculateFees($data['employee_count'], $plan);

        return Subscription::create([
            'company_id'           => $company->id,
            'plan_id'              => $plan->id,
            'employee_count'       => $data['employee_count'],
            'total_amount_etb'     => $fees['total_amount_etb'],
            'service_fee_etb'      => $fees['service_fee_etb'],
            'absenteeism_fee_etb'  => $fees['absenteeism_fee_etb'],
            'billing_cycle'        => $data['billing_cycle'] ?? 'quarterly',
            'billing_date'         => $data['billing_date'],
            'period_start'         => $data['period_start'],
            'period_end'           => $data['period_end'],
            'status'               => 'pending',
        ]);
    }
}
