<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->id,
            'fan_number'  => $this->fan_number,
            'job_title'   => $this->job_title,
            'level'       => $this->level,
            'department'  => $this->department,
            'is_enrolled'    => $this->is_enrolled,
            'enrolled_at'    => $this->enrolled_at,
            'payment_status' => $this->payment_status ?? 'unpaid',
            'is_banned'      => $this->is_banned,
            'banned_until'   => $this->banned_until?->toIso8601String(),
            'ban_reason'     => $this->ban_reason,
            'gym_tier'    => $this->getGymTier(),
            'user'        => new UserResource($this->whenLoaded('user')),
            'company'     => new CompanyResource($this->whenLoaded('company')),
            'active_membership' => new MembershipResource($this->whenLoaded('activeMembership')),
            'created_at'  => $this->created_at,
        ];
    }
}
