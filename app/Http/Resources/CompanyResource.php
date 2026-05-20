<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CompanyResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                       => $this->id,
            'name'                     => $this->name,
            'industry'                 => $this->industry,
            'contact_person'           => $this->contact_person,
            'contact_email'            => $this->contact_email,
            'contact_phone'            => $this->contact_phone,
            'address'                  => $this->address,
            'city'                     => $this->city,
            'tier'                     => $this->tier,
            'employee_count'           => $this->employee_count,
            'employees_count'          => $this->employees_count ?? 0,
            'enrolled_employees_count' => $this->enrolled_employees_count ?? 0,
            'is_active'                => $this->is_active,
            'contract_start'           => $this->contract_start,
            'contract_end'             => $this->contract_end,
            'tin_number'               => $this->tin_number,
            'logo_path'                => $this->logo_path,

            // Business licence
            'business_license_path'    => $this->business_license_path,
            'business_license_url'     => $this->business_license_path
                                            ? Storage::disk('public')->url($this->business_license_path)
                                            : null,
            'business_license_status'  => $this->business_license_status,

            'created_at'               => $this->created_at,

            // Included only when explicitly loaded (e.g. show() endpoint)
            'employees' => $this->whenLoaded('employees', fn() =>
                $this->employees->map(fn($e) => [
                    'id'                  => $e->id,
                    'name'                => $e->user?->name,
                    'email'               => $e->user?->email,
                    'phone'               => $e->user?->phone,
                    'fan_number'          => $e->fan_number,
                    'job_title'           => $e->job_title,
                    'department'          => $e->department,
                    'branch'              => $e->branch,
                    'level'               => $e->level,
                    'package'             => match($e->level) {
                        'chief'    => 'platinum',
                        'director' => 'basic_plus',
                        default    => 'basic',
                    },
                    'registration_status' => $e->registration_status ?? 'approved',
                    'is_enrolled'         => $e->is_enrolled,
                    'membership_status'   => $e->activeMembership?->status
                                             ?? ($e->is_enrolled ? 'active' : 'inactive'),
                    'enrolled_at'         => $e->enrolled_at?->toDateString()
                                             ?? $e->created_at->toDateString(),
                ])
            ),
        ];
    }
}
