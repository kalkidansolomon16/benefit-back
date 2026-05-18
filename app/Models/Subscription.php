<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'plan_id', 'employee_count',
        'total_amount_etb', 'service_fee_etb', 'absenteeism_fee_etb',
        'billing_cycle', 'billing_date', 'period_start', 'period_end', 'status',
    ];

    protected $casts = [
        'billing_date' => 'date',
        'period_start' => 'date',
        'period_end'   => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function getFitaccessRevenueAttribute(): float
    {
        return $this->service_fee_etb + $this->absenteeism_fee_etb;
    }
}
