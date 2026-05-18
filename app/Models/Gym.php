<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Gym extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'contact_person', 'contact_phone', 'contact_email',
        'address', 'sub_city', 'city', 'latitude', 'longitude',
        'tier', 'max_capacity', 'current_members',
        'monthly_fee_etb', 'quarterly_fee_etb', 'annual_fee_etb',
        'facilities', 'opening_hours', 'logo_path', 'photo_paths',
        'is_active', 'is_partner', 'partnership_start', 'partnership_end',
    ];

    protected $casts = [
        'facilities'       => 'array',
        'opening_hours'    => 'array',
        'photo_paths'      => 'array',
        'is_active'        => 'boolean',
        'is_partner'       => 'boolean',
        'partnership_start'=> 'date',
        'partnership_end'  => 'date',
    ];

    // Virtual attribute (computed in PHP since SQLite/MySQL both handle it)
    public function getAvailableSlotsAttribute(): int
    {
        return $this->max_capacity - $this->current_members;
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(Checkin::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->hasMany(Membership::class)->where('status', 'active');
    }

    public function hasCapacity(): bool
    {
        return $this->available_slots > 0;
    }
}
