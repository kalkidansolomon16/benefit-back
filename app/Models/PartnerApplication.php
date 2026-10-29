<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerApplication extends Model
{
    protected $fillable = [
        'user_id',
        'parent_gym_id',
        'branch_user_id',
        'facility_name',
        'branch_name',
        'categories',
        'contact_person',
        'contact_phone',
        'contact_email',
        'tin_number',
        'business_license_path',
        'city',
        'sub_city',
        'woreda',
        'landmark',
        'google_maps_link',
        'weekday_open',
        'weekday_close',
        'weekend_open',
        'weekend_close',
        'operating_hours_summary',
        'max_capacity',
        'amenities',
        'branches',
        'status',
        'rejection_reason',
    ];

    protected $casts = [
        'categories' => 'array',
        'amenities'  => 'array',
        'branches'   => 'array',
    ];

    /** Login created for a branch an existing owner asked to add */
    public function branchUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'branch_user_id');
    }

    public function parentGym(): BelongsTo
    {
        return $this->belongsTo(Gym::class, 'parent_gym_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
