<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's request to join FitAccess, submitted from the public sign-up
 * form. Nothing else (company, HR login, branch logins) exists until an
 * admin approves it.
 */
class CompanyApplication extends Model
{
    protected $fillable = [
        'reference', 'company_name', 'industry', 'company_size', 'tin_number',
        'preferred_payment_method', 'contact_name', 'job_title', 'contact_email',
        'contact_phone', 'branches', 'status', 'rejection_reason', 'company_id',
        'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'branches'    => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Human-friendly number quoted in emails, e.g. FA-C000042 */
    public static function makeReference(int $id): string
    {
        return 'FA-C' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
