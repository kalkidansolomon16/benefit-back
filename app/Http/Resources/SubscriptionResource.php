<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                   => $this->id,
            'employee_count'       => $this->employee_count,
            'total_amount_etb'     => $this->total_amount_etb,
            'service_fee_etb'      => $this->service_fee_etb,
            'absenteeism_fee_etb'  => $this->absenteeism_fee_etb,
            'fitaccess_revenue'    => $this->fitaccess_revenue,
            'billing_cycle'        => $this->billing_cycle,
            'billing_date'         => $this->billing_date,
            'period_start'         => $this->period_start,
            'period_end'           => $this->period_end,
            'status'               => $this->status,
            'company'              => new CompanyResource($this->whenLoaded('company')),
            'plan'                 => $this->whenLoaded('plan'),
            'created_at'           => $this->created_at,
        ];
    }
}
