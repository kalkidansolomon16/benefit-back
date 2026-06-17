<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password', 'role',
        'fan_number', 'photo_path', 'phone', 'is_active',
        'must_reset_password', 'password_reset_token', 'password_reset_expires_at',
        'created_by', 'company_id', 'gym_id',
        'telegram_chat_id', 'telegram_link_token', 'telegram_link_expires_at',
    ];

    protected $hidden = ['password', 'remember_token', 'password_reset_token'];

    protected function casts(): array
    {
        return [
            'password'                   => 'hashed',
            'is_active'                  => 'boolean',
            'must_reset_password'        => 'boolean',
            'password_reset_expires_at'  => 'datetime',
            'telegram_link_expires_at'   => 'datetime',
        ];
    }

    /* ── Relationships ─────────────────────────────────────────── */

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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }

    public function userPermissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    /* ── Role helpers ──────────────────────────────────────────── */

    public function isSuperAdmin(): bool { return $this->role === 'super_admin'; }
    public function isAdmin(): bool      { return in_array($this->role, ['fitaccess_admin', 'admin_finance', 'admin_support']); }
    public function isHR(): bool         { return in_array($this->role, ['company_hr', 'company_finance', 'company_ceo']); }
    public function isEmployee(): bool   { return $this->role === 'employee'; }
    public function isGymUser(): bool    { return in_array($this->role, ['gym_partner', 'gym_staff']); }

    /** All admin-panel roles */
    public const ADMIN_ROLES = ['super_admin', 'fitaccess_admin', 'admin_finance', 'admin_support'];
    /** Roles that admin can create (sub-roles) */
    public const ADMIN_SUB_ROLES = ['admin_finance', 'admin_support'];

    /** Dynamically fetch all admin sub-role names from the roles table (excludes top-level admin). */
    public static function dynamicAdminSubRoles(): array
    {
        return \App\Models\Role::where('scope', 'admin')
            ->where('name', '!=', 'fitaccess_admin')
            ->pluck('name')
            ->toArray();
    }
    /** Roles HR can create */
    public const COMPANY_SUB_ROLES = ['co_hr', 'co_executive', 'co_finance', 'company_finance', 'company_ceo'];
    /** Roles gym owner can create */
    public const GYM_SUB_ROLES = ['gym_hr', 'gym_executive', 'gym_finance'];

    /* ── Permission system ─────────────────────────────────────── */

    /**
     * Check if user has a given permission.
     * - super_admin always returns true.
     * - fitaccess_admin gets all admin permissions except permissions.manage restriction.
     * - Others: check user_permissions override first, then role_permissions default.
     */
    public function hasPermission(string $permission): bool
    {
        if (in_array($this->role, ['super_admin', 'fitaccess_admin'])) {
            return true;
        }

        // Check per-user override
        $override = UserPermission::where('user_id', $this->id)
            ->where('permission_name', $permission)
            ->first();

        if ($override !== null) {
            return $override->granted;
        }

        // Check role default
        return RolePermission::where('role', $this->role)
            ->where('permission_name', $permission)
            ->exists();
    }

    /**
     * Return all effective permissions for this user.
     * Merges role defaults with user-specific overrides.
     */
    public function effectivePermissions(): array
    {
        if (in_array($this->role, ['super_admin', 'fitaccess_admin'])) {
            return Permission::pluck('name')->toArray();
        }

        // Start with role defaults
        $rolePerms = RolePermission::where('role', $this->role)
            ->pluck('permission_name')
            ->toArray();

        $perms = array_flip($rolePerms); // name => true

        // Apply user overrides
        $overrides = UserPermission::where('user_id', $this->id)->get();
        foreach ($overrides as $o) {
            if ($o->granted) {
                $perms[$o->permission_name] = true;
            } else {
                unset($perms[$o->permission_name]);
            }
        }

        return array_keys($perms);
    }
}
