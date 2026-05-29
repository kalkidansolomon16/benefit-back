<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'company_id', 'fan_number', 'photo_path',
        'job_title', 'level', 'department', 'branch',
        'request_note', 'registration_status', 'payment_status',
        'is_enrolled', 'enrolled_at',
        'banned_until', 'ban_reason',
    ];

    protected $casts = [
        'is_enrolled'  => 'boolean',
        'enrolled_at'  => 'datetime',
        'banned_until' => 'datetime',
    ];

    public function getIsBannedAttribute(): bool
    {
        return $this->banned_until !== null && $this->banned_until->isFuture();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function activeMembership(): HasOne
    {
        return $this->hasOne(Membership::class)->where('status', 'active')->latestOfMany();
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(Checkin::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function wellnessPrograms(): BelongsToMany
    {
        return $this->belongsToMany(WellnessProgram::class, 'employee_wellness', 'employee_id', 'program_id')
                    ->withPivot('status', 'enrolled_at', 'completed_at', 'notes')
                    ->withTimestamps();
    }

    public function getGymTier(): string
    {
        return match ($this->level) {
            'chief'    => 'premium',
            'director' => 'basic_plus',
            default    => 'basic',
        };
    }
}
