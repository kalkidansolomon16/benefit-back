<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyGymSelection extends Model
{
    protected $fillable = ['employee_id', 'gym_id', 'selection_date'];

    protected $casts = [
        'selection_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }

    /** Convenience: today's selection for a given employee */
    public static function todayFor(int $employeeId): ?self
    {
        return self::where('employee_id', $employeeId)
            ->where('selection_date', now()->toDateString())
            ->with('gym')
            ->first();
    }
}
