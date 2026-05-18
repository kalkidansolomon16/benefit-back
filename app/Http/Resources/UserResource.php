<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'email'      => $this->email,
            'role'       => $this->role,
            'fan_number' => $this->fan_number,
            'phone'      => $this->phone,
            'photo_path' => $this->photo_path,
            'is_active'  => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
