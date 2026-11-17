<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GymStaff extends Model
{
    protected $table = 'gym_staff';

    protected $fillable = [
        'user_id', 'gym_id', 'role',
        'is_active', 'must_change_password',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'must_change_password' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
