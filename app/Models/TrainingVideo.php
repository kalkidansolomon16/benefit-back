<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * v2 training video. video_url points at the video service and is only
 * returned to members whose plan includes premium training.
 */
class TrainingVideo extends Model
{
    protected $fillable = ['title', 'category', 'level', 'coach', 'minutes', 'description', 'equipment', 'what_you_need', 'thumbnail_path', 'video_url', 'is_premium', 'is_featured', 'is_published', 'sort'];

    protected $hidden = ['video_url'];

    protected $casts = ['equipment' => 'array', 'what_you_need' => 'array', 'is_premium' => 'boolean', 'is_featured' => 'boolean', 'is_published' => 'boolean'];
}
