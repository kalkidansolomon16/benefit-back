<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'industry', 'contact_person', 'contact_email',
        'contact_phone', 'address', 'city', 'tier', 'employee_count',
        'logo_path', 'tin_number', 'is_active', 'contract_start', 'contract_end',
        'business_license_path', 'business_license_status',
        'preferred_payment_method',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'contract_start' => 'date',
        'contract_end'   => 'date',
    ];

    /**
     * The HR user whose email matches this company's contact_email.
     * Used to sync is_active when the admin activates / deactivates a company.
     */
    public function hrUser(): HasOne
    {
        return $this->hasOne(User::class, 'email', 'contact_email');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->where('status', 'active')->latestOfMany();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isContractActive(): bool
    {
        return $this->is_active
            && $this->contract_start?->isPast()
            && $this->contract_end?->isFuture();
    }
}
