<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's identity-verification selfie and its review.
 * photo_path is on the private disk; serve it only through the
 * authenticated photo endpoints, never as a public URL.
 */
class MemberVerification extends Model
{
    public const PENDING = 'pending';
    public const VERIFIED = 'verified';
    public const REJECTED = 'rejected';

    protected $fillable = ['user_id', 'photo_path', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at_gym_id', 'reviewed_at'];

    protected $hidden = ['photo_path'];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
