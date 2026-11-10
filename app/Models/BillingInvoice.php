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
        'company_id', 'company_branch_id', 'branch_scoped', 'employee_id', 'invoice_number', 'billing_period',
        'period_months', 'period_start', 'period_end',
        'total_amount', 'due_date', 'notes', 'status',
        'sent_at', 'paid_at',
    ];

    protected $casts = [
        'branch_scoped' => 'boolean',
        'due_date' => 'date',
        'period_months' => 'integer',
        'period_start'  => 'date',
        'period_end'    => 'date',
        'sent_at'  => 'datetime',
        'paid_at'  => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companyBranch(): BelongsTo
    {
        return $this->belongsTo(CompanyBranch::class);
    }

    /**
     * Branch logins can only reach invoices issued to their own branch.
     * (The caller still has to check that the invoice belongs to the user's company.)
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($user->isCompanyBranchLogin()) {
            return $this->branch_scoped && (int) $this->company_branch_id === (int) $user->company_branch_id;
        }

        return true;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingInvoiceItem::class);
    }

    /** The employees this invoice pays for (set when it is generated) */
    public function coveredEmployees(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'billing_invoice_employees')->withTimestamps();
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
        // The covered-employee list has no database foreign key; remove it with a hard delete
        static::forceDeleted(fn (BillingInvoice $invoice) => $invoice->coveredEmployees()->detach());

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
