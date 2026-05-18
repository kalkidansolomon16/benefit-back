<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class GymResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'contact_phone'   => $this->contact_phone,
            'contact_email'   => $this->contact_email,
            'address'         => $this->address,
            'sub_city'        => $this->sub_city,
            'city'            => $this->city,
            'latitude'        => $this->latitude,
            'longitude'       => $this->longitude,
            'tier'            => $this->tier,
            'max_capacity'    => $this->max_capacity,
            'current_members' => $this->current_members,
            'available_slots' => $this->available_slots,
            'monthly_fee_etb' => $this->monthly_fee_etb,
            'facilities'      => $this->facilities,
            'opening_hours'   => $this->opening_hours,
            'logo_path'       => $this->logo_path,
            'photo_paths'     => $this->photo_paths,
            'is_active'       => $this->is_active,
            'is_partner'      => $this->is_partner,
            'created_at'      => $this->created_at,
        ];
    }
}
