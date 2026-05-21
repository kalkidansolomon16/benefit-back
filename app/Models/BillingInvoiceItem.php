<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingInvoiceItem extends Model
{
    protected $fillable = [
        'billing_invoice_id', 'plan_tier', 'plan_name',
        'employee_count', 'unit_price', 'subtotal',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'subtotal'   => 'float',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }
}
