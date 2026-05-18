<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WellnessProgram extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title', 'description', 'category', 'provider',
        'provider_contact', 'location', 'is_online',
        'start_date', 'end_date', 'max_participants', 'is_active',
    ];

    protected $casts = [
        'is_online'  => 'boolean',
        'is_active'  => 'boolean',
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_wellness', 'program_id', 'employee_id')
                    ->withPivot('status', 'enrolled_at', 'completed_at', 'notes')
                    ->withTimestamps();
    }
}
