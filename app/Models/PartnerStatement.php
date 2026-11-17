<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerStatement extends Model
{
    protected $fillable = [
        'gym_id', 'period', 'period_start', 'period_end', 'total_visits', 'unique_members', 'total_amount', 'lines',
        'status', 'sent_at', 'invoice_due_on',
        'partner_invoice_path', 'partner_invoice_name', 'partner_invoice_number', 'partner_invoice_type',
        'partner_invoice_amount', 'partner_note', 'invoiced_at',
        'receipt_path', 'receipt_name', 'payment_reference', 'paid_at', 'paid_by', 'admin_note',
    ];

    protected $casts = [
        'period_start'           => 'date',
        'period_end'             => 'date',
        'lines'                  => 'array',
        'total_amount'           => 'decimal:2',
        'partner_invoice_amount' => 'decimal:2',
        'sent_at'                => 'datetime',
        'invoice_due_on'         => 'date',
        'invoiced_at'            => 'datetime',
        'paid_at'                => 'datetime',
    ];

    protected $hidden = ['partner_invoice_path', 'receipt_path'];

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'sent'], true);
    }
}
