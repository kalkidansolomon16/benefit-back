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
 *
 * Each token also has a 6-digit front-desk code that reception can type
 * in when the scan fails. Redeeming either one uses up the token.
 */
class QrTokenService
{
    /** v2: the member app shows a 30-second countdown. */
    public const TTL_SECONDS = 30;

    public const DESK_CODE_LENGTH = 6;

    /**
     * @return array{token: string, desk_code: string, expires_at: CarbonImmutable}
     */
    public function issue(User $user): array
    {
        $plain = Str::random(64);
        $expiresAt = CarbonImmutable::now()->addSeconds(self::TTL_SECONDS);

        QrToken::where('user_id', $user->id)->whereNull('used_at')->delete();

        $deskCode = $this->freshDeskCode();

        QrToken::create([
            'user_id' => $user->id,
            'token' => self::hash($plain),
            'desk_code_hash' => self::deskHash($deskCode),
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $plain, 'desk_code' => $deskCode, 'expires_at' => $expiresAt];
    }

    /**
     * Atomically marks the token as used and returns it. Returns null if the
     * token is unknown, expired, or was already used. The conditional UPDATE
     * guarantees that only one of two concurrent scans can win.
     */
    public function consume(string $plain, int $gymId): ?QrToken
    {
        return $this->claim('token', self::hash($plain), $gymId);
    }

    /** Same as consume(), by the 6-digit front-desk code. */
    public function consumeDeskCode(string $code, int $gymId): ?QrToken
    {
        if (!preg_match('/^\d{'.self::DESK_CODE_LENGTH.'}$/', $code)) {
            return null;
        }

        return $this->claim('desk_code_hash', self::deskHash($code), $gymId);
    }

    /** Removes used and expired tokens; call from the scheduler. */
    public function prune(): int
    {
        return QrToken::where('expires_at', '<', now()->subDay())->delete();
    }

    private function claim(string $column, string $value, int $gymId): ?QrToken
    {
        $id = QrToken::where($column, $value)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->value('id');

        if ($id === null) {
            return null;
        }

        $claimed = QrToken::whereKey($id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now(), 'used_at_gym_id' => $gymId]);

        return $claimed === 1 ? QrToken::find($id) : null;
    }

    /** A code not used by any token that is still live. */
    private function freshDeskCode(): string
    {
        for ($i = 0; $i < 20; $i++) {
            $code = str_pad((string) random_int(0, 10 ** self::DESK_CODE_LENGTH - 1), self::DESK_CODE_LENGTH, '0', STR_PAD_LEFT);
            $taken = QrToken::where('desk_code_hash', self::deskHash($code))
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->exists();
            if (!$taken) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not allocate a front-desk code.');
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    private static function deskHash(string $code): string
    {
        return hash_hmac('sha256', 'desk:'.$code, (string) config('app.key'));
    }
}
