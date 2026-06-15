<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    protected $fillable = [
        'bank_name', 'account_name', 'account_number', 'instructions', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function billingPayments(): HasMany
    {
        return $this->hasMany(BillingPayment::class);
    }
}
