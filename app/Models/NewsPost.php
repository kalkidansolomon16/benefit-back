<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** News / promotions published by the FitAccess admin for employees. */
class NewsPost extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title', 'category', 'excerpt', 'body', 'image_path', 'link_url', 'link_label',
        'company_id', 'is_featured', 'is_published', 'published_at', 'created_by',
    ];

    protected $casts = [
        'is_featured'  => 'boolean',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Published, already live, and meant for everyone or for this company. */
    public function scopeVisibleTo(Builder $q, ?int $companyId): Builder
    {
        return $q->where('is_published', true)
            ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId));
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? url('/api/v1/files/' . ltrim($this->image_path, '/')) : null;
    }
}
