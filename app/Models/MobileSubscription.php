<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MobileSubscription extends Model
{
    use SoftDeletes;

    protected $table = 'mobile_subscriptions';

    protected $fillable = [
        'user_id', 'plan_id', 'status', 'billing_cycle',
        'amount_paid', 'payment_reference',
        'start_date', 'end_date', 'auto_renew',
    ];

    protected $casts = [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'auto_renew'  => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }

    public function daysRemaining(): int
    {
        return max(0, now()->diffInDays($this->end_date, false));
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->end_date->isFuture();
    }
}
