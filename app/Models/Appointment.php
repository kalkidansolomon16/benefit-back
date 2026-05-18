<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id', 'doctor_name', 'clinic_name', 'specialty',
        'appointment_at', 'status', 'notes', 'meeting_link', 'is_online',
    ];

    protected $casts = [
        'appointment_at' => 'datetime',
        'is_online'      => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
