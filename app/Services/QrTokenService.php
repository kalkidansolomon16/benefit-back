<?php

namespace App\Services;

use App\Models\QrToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Issues and redeems member check-in QR tokens.
 *
 * Tokens are short-lived (TTL_SECONDS), single-use, and stored only as a
 * SHA-256 hash, so a leaked database row can't be turned into a working QR.
 * Issuing a new token revokes the member's earlier unused tokens, so a
 * screenshot of an older code stops working once the app refreshes.
 */
class QrTokenService
{
    public const TTL_SECONDS = 60;

    /**
     * @return array{token: string, expires_at: CarbonImmutable}
     */
    public function issue(User $user): array
    {
        $plain = Str::random(64);
        $expiresAt = CarbonImmutable::now()->addSeconds(self::TTL_SECONDS);

        QrToken::where('user_id', $user->id)->whereNull('used_at')->delete();

        QrToken::create([
            'user_id' => $user->id,
            'token' => self::hash($plain),
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $plain, 'expires_at' => $expiresAt];
    }

    /**
     * Atomically marks the token as used and returns it. Returns null if the
     * token is unknown, expired, or was already used. The conditional UPDATE
     * guarantees that only one of two concurrent scans can win.
     */
    public function consume(string $plain, int $gymId): ?QrToken
    {
        $hash = self::hash($plain);

        $claimed = QrToken::where('token', $hash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now(), 'used_at_gym_id' => $gymId]);

        if ($claimed !== 1) {
            return null;
        }

        return QrToken::where('token', $hash)->first();
    }

    /** Removes used and expired tokens; call from the scheduler. */
    public function prune(): int
    {
        return QrToken::where('expires_at', '<', now()->subDay())->delete();
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
