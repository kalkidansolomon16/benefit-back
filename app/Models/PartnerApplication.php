<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerApplication extends Model
{
    protected $fillable = [
        'reference',
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
        'website',
        'has_permits',
        'has_certified_equipment',
        'has_reception',
        'business_license_path',
        'city',
        'sub_city',
        'woreda',
        'landmark',
        'google_maps_link',
        'latitude',
        'longitude',
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
        'has_permits'             => 'boolean',
        'has_certified_equipment' => 'boolean',
        'has_reception'           => 'boolean',
        'branches'   => 'array',
        'latitude'   => 'float',
        'longitude'  => 'float',
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

    /** The owner's login; null until an admin approves a public application */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Human-friendly number quoted in emails, e.g. FA-P000042 */
    public static function makeReference(int $id): string
    {
        return 'FA-P' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
