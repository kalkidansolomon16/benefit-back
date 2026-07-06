<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Checkin extends Model
{
    protected $fillable = [
    'membership_id', 'gym_id', 'employee_id',
    'user_id',
    'checked_in_at', 'checked_out_at',
    'recorded_by', 'method',
        ];

    protected $casts = [
        'checked_in_at'  => 'datetime',
        'checked_out_at' => 'datetime',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function getDurationMinutesAttribute(): ?int
    {
        if ($this->checked_out_at) {
            return $this->checked_in_at->diffInMinutes($this->checked_out_at);
        }
        return null;
    }
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
    return $this->belongsTo(User::class);
    }
}
