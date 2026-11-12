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

    protected static function booted(): void
    {
        // Every employee gets a FitAccess card number when created.
        static::creating(function (Employee $employee) {
            $employee->card_number ??= \App\Support\CardNumber::generate();
        });
    }

    protected $fillable = [
        'user_id', 'company_id', 'company_branch_id', 'fan_number', 'photo_path',
        'job_title', 'level', 'department', 'branch',
        'request_note', 'registration_status', 'payment_status', 'paid_period', 'paid_until',
        'is_enrolled', 'enrolled_at',
        'admin_approval_status', 'payment_preference',
        'is_enrolled', 'enrolled_at',
        'banned_until', 'ban_reason', 'show_in_gym_list',
    ];

    public function companyBranch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CompanyBranch::class);
    }

    protected $casts = [
        'show_in_gym_list' => 'boolean',
        'is_enrolled'  => 'boolean',
        'enrolled_at'  => 'datetime',
        'banned_until' => 'datetime',
        'paid_until'   => 'date',
    ];

    /** Is this employee's access paid for today (their company's invoice covers today)? */
    public function hasPaidAccess(): bool
    {
        return \App\Support\BillingPeriod::covers($this->paid_until);
    }

    /** active | expired (paid period ended) | unpaid (never paid) | suspended | inactive */
    public function accessStatus(): string
    {
        if (!$this->is_enrolled) {
            return 'inactive';
        }
        if (!$this->hasPaidAccess()) {
            return $this->paid_until ? 'expired' : 'unpaid';
        }

        return $this->activeMembership?->status ?? 'active';
    }

    /** Short reason shown when check-in is refused because the paid period ended */
    public function paidAccessMessage(): string
    {
        return $this->paid_until
            ? 'FitAccess access ended on ' . $this->paid_until->format('j M Y') . '. The company needs to pay its next invoice to renew it.'
            : 'FitAccess access has not been paid for yet. It starts once the company pays its invoice.';
    }

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

    public static function levelToPackage(string $level): string
    {
        return match ($level) {
            'chief'    => 'platinum',
            'director' => 'basic_plus',
            default    => 'basic',
        };
    }

    public static function packageToLevel(string $package): string
    {
        $plan = MembershipPlan::where('tier', $package)->first();

        if ($plan) {
            if ($plan->target_level && $plan->target_level !== 'all') {
                return $plan->target_level;
            }

            // No explicit target_level set — rank by price among active plans
            // (cheapest → staff, priciest → chief, everything else → director).
            $tiers = MembershipPlan::where('is_active', true)->orderBy('monthly_fee_etb')->pluck('tier')->values();
            $index = $tiers->search($package);
            $count = $tiers->count();

            if ($index !== false && $count > 0) {
                $percentile = $count > 1 ? $index / ($count - 1) : 0;

                return match (true) {
                    $percentile >= 0.66 => 'chief',
                    $percentile >= 0.33 => 'director',
                    default              => 'staff',
                };
            }
        }

        // Legacy fallback for canonical tier keys not backed by a plan record
        $norm = strtolower(preg_replace('/^[a-z]+_(?=basic|premium|platinum|gold|silver)/i', '', $package) ?? $package);

        return match (true) {
            in_array($norm, ['platinum', 'premium', 'gold']) => 'chief',
            str_contains($norm, 'basic_plus')                => 'director',
            str_contains($norm, 'manager')                   => 'manager',
            default                                          => 'staff',
        };
    }
}
