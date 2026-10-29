<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A branch of a company. The Company record itself is the main (head office);
 * each branch has its own login (a company_hr user with company_branch_id set).
 */
class CompanyBranch extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'contact_person', 'city', 'sub_city',
        'address', 'landmark', 'phone', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** The branch's own login */
    public function loginUser(): HasOne
    {
        return $this->hasOne(User::class, 'company_branch_id');
    }
}
