<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v2 news article (Home carousel and News page). */
class NewsArticle extends Model
{
    public const CATEGORIES = ['partner_news', 'wellness_tips', 'offers', 'announcements'];

    protected $fillable = ['category', 'title', 'excerpt', 'body', 'image_path', 'read_minutes', 'is_published', 'published_at', 'created_by'];

    protected $casts = ['body' => 'array', 'is_published' => 'boolean', 'published_at' => 'datetime'];

    public function scopeVisible($q)
    {
        return $q->where('is_published', true)->where('published_at', '<=', now());
    }
}
