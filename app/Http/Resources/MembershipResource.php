<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->id,
            'start_date'        => $this->start_date,
            'end_date'          => $this->end_date,
            'status'            => $this->status,
            'suspension_reason' => $this->suspension_reason,
            'employee'          => new EmployeeResource($this->whenLoaded('employee')),
            'gym'               => new GymResource($this->whenLoaded('gym')),
            'plan'              => $this->whenLoaded('plan'),
            'created_at'        => $this->created_at,
        ];
    }
}
