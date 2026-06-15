<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Auth\Passwords\CanResetPassword as CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;

class User extends Authenticatable implements CanResetPasswordContract
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;
    use CanResetPassword;

    protected $fillable = [
        'name', 'email', 'password', 'role',
        'fan_number', 'photo_path', 'phone', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password'  => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    // Relationships
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    // Helpers
    public function isSuperAdmin(): bool  { return $this->role === 'super_admin'; }
    public function isAdmin(): bool       { return $this->role === 'fitaccess_admin'; }
    public function isHR(): bool          { return $this->role === 'company_hr'; }
    public function isEmployee(): bool    { return $this->role === 'employee'; }
    public function isGymStaff(): bool    { return $this->role === 'gym_staff'; }
}
