<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Role extends Model
{
    protected $fillable = [
        'name', 'label', 'scope',
        'company_id', 'gym_id',
        'is_system', 'created_by',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function gym(): BelongsTo
    {
        return $this->belongsTo(Gym::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ── Helper: generate a unique slug from label ───────── */
    public static function makeSlug(string $label, string $prefix): string
    {
        $base = $prefix . '_' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($label)));
        $slug = $base;
        $i    = 2;

        while (self::where('name', $slug)->exists()) {
            $slug = $base . '_' . $i++;
        }

        return $slug;
    }
}
