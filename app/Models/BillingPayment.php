<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPayment extends Model
{
    protected $fillable = [
        'billing_invoice_id', 'company_id', 'amount',
        'payment_channel', 'chapa_tx_ref', 'chapa_verified_at',
        'payment_method_id',
        'payment_method_bank', 'payment_method_account_name', 'payment_method_account_number',
        'receipt_path', 'status',
        'submitted_at', 'verified_at', 'verified_by', 'admin_notes',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'verified_at'  => 'datetime',
        'submitted_at'      => 'datetime',
        'verified_at'       => 'datetime',
        'chapa_verified_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
