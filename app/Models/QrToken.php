<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use check-in token. The `token` column holds the SHA-256 hash of
 * the value shown in the member's QR code, never the value itself.
 * Issue and consume tokens only through App\Services\QrTokenService.
 */
class QrToken extends Model
{
    protected $fillable = ['user_id', 'token', 'expires_at', 'used_at', 'used_at_gym_id'];

    protected $hidden = ['token'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
