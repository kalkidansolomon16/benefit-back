<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BillingInvoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'invoice_number', 'billing_period',
        'total_amount', 'due_date', 'notes', 'status',
        'sent_at', 'paid_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'sent_at'  => 'datetime',
        'paid_at'  => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingInvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class);
    }

    public function latestPayment()
    {
        return $this->hasOne(BillingPayment::class)->latestOfMany();
    }

    public function negotiations(): HasMany
    {
        return $this->hasMany(BillingNegotiation::class);
    }

    public function latestNegotiation()
    {
        return $this->hasOne(BillingNegotiation::class)->latestOfMany();
    }

    /** Auto-generate invoice number on creation. */
    protected static function booted(): void
    {
        static::creating(function (BillingInvoice $invoice) {
            if (empty($invoice->invoice_number)) {
                $year  = now()->format('Y');
                $month = now()->format('m');
                $seq   = str_pad(self::whereYear('created_at', $year)->count() + 1, 4, '0', STR_PAD_LEFT);
                $invoice->invoice_number = "BINV-{$year}{$month}-{$seq}";
            }
        });
    }
}
