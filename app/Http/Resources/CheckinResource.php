<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckinResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'checked_in_at'    => $this->checked_in_at,
            'checked_out_at'   => $this->checked_out_at,
            'duration_minutes' => $this->duration_minutes,
            'method'           => $this->method,
            'recorded_by'      => $this->recorded_by,
            'employee'         => new EmployeeResource($this->whenLoaded('employee')),
            'gym'              => new GymResource($this->whenLoaded('gym')),
        ];
    }
}
