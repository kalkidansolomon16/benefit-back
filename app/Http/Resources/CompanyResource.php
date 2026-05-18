<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'industry'       => $this->industry,
            'contact_person' => $this->contact_person,
            'contact_email'  => $this->contact_email,
            'contact_phone'  => $this->contact_phone,
            'city'           => $this->city,
            'tier'           => $this->tier,
            'employee_count' => $this->employee_count,
            'is_active'      => $this->is_active,
            'contract_start' => $this->contract_start,
            'contract_end'   => $this->contract_end,
            'logo_path'      => $this->logo_path,
            'created_at'     => $this->created_at,
        ];
    }
}
