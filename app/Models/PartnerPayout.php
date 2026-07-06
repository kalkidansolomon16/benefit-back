<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerPayout extends Model
{
    protected $fillable = [
        'gym_id', 'period_start', 'period_end', 'period',
        'total_checkins', 'per_visit_rate', 'total_amount',
        'status', 'paid_at', 'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'paid_at'      => 'datetime',
    ];

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }
}
