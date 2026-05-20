<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerApplication extends Model
{
    protected $fillable = [
        'user_id',
        'facility_name',
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
        'status',
        'rejection_reason',
    ];

    protected $casts = [
        'categories' => 'array',
        'amenities'  => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
