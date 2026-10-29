<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issues and validates API session tokens (Sanctum personal access tokens).
 *
 * Every session ends on the server, not just in the app (INSA CWE-613):
 *  - idle timeout: the token stops working if it hasn't been used for a while;
 *  - absolute lifetime: the token stops working after a fixed time regardless.
 * Limits depend on the account type, since a shared gym reception phone and
 * a member's personal phone carry different risks.
 *
 * Members (member app) get short-lived access tokens plus a rotating refresh
 * token (INSA member-app finding, CWE-613):
 *  - access token: 15 minutes, used for API calls;
 *  - refresh token: 7 days, only accepted by POST /auth/refresh, replaced on
 *    every use; presenting an already-used one revokes the whole session;
 *  - a session (token family) ends 14 days after sign-in regardless.
 */
class SessionTokenService
{
    public const REFRESH_ABILITY = 'refresh';

    public const ACCESS_TOKEN_MINUTES = 15;

    public const REFRESH_TOKEN_MINUTES = 7 * 24 * 60;

    public const SESSION_MAX_MINUTES = 14 * 24 * 60;

    private const GYM_ROLES = ['gym_partner', 'gym_staff', 'gym_hr', 'gym_executive', 'gym_finance'];

    private const MEMBER_ROLES = ['member', 'employee'];

    /** Minutes: [idle timeout, absolute lifetime]. */
    private const POLICIES = [
        'gym' => [12 * 60, 7 * 24 * 60],
        'member' => [self::ACCESS_TOKEN_MINUTES, self::ACCESS_TOKEN_MINUTES], // + refresh token
        'default' => [2 * 60, 12 * 60], // web portal: admins, HR, finance, ...
    ];

    /**
     * @return array{idle: int, lifetime: int} minutes
     */
    public static function policyFor(?User $user): array
    {
        [$idle, $lifetime] = self::POLICIES[self::policyKey($user)];

        return ['idle' => $idle, 'lifetime' => $lifetime];
    }

    public static function usesRefreshTokens(?User $user): bool
    {
        return self::policyKey($user) === 'member';
    }

    public function issue(User $user, ?string $deviceName = null, array $abilities = ['*']): NewAccessToken
    {
        $policy = self::policyFor($user);

        return $user->createToken(
            self::deviceLabel($deviceName),
            $abilities,
            now()->addMinutes($policy['lifetime']),
        );
    }

    /**
     * Starts a session: one access token, plus a refresh token for members.
     *
     * @return array{access: NewAccessToken, refresh: ?NewAccessToken}
     */
    public function issueSession(User $user, ?string $deviceName = null, array $abilities = ['*']): array
    {
        if (! self::usesRefreshTokens($user)) {
            return ['access' => $this->issue($user, $deviceName, $abilities), 'refresh' => null];
        }

        return $this->issuePair($user, self::deviceLabel($deviceName), $abilities, (string) Str::uuid(), now());
    }

    /**
     * Exchanges a refresh token for a new access + refresh pair (rotation).
     * Returns null if the token is unknown, expired, not a refresh token, or
     * was already used; reuse revokes every token of that session.
     *
     * @return array{access: NewAccessToken, refresh: NewAccessToken}|null
     */
    public function refresh(string $plainRefreshToken): ?array
    {
        $token = PersonalAccessToken::findToken($plainRefreshToken);
        if (! $token || ! self::isRefreshToken($token) || ! $token->family_id) {
            return null;
        }

        $user = $token->tokenable;
        if (! $user instanceof User || ! $user->is_active) {
            return null;
        }

        // Atomic claim: only one of two concurrent refreshes can win.
        $claimed = PersonalAccessToken::whereKey($token->id)->whereNull('used_at')->update(['used_at' => now()]);
        if ($claimed !== 1) {
            Log::warning('auth.refresh_token_reuse', ['user_id' => $user->id, 'family_id' => $token->family_id]);
            $this->revokeFamily($token->family_id);

            return null;
        }

        if (! self::refreshTokenUsable($token)) {
            return null;
        }

        // The old access token(s) of this session end with the rotation. The
        // used refresh token is kept until it expires, so reuse is detectable.
        PersonalAccessToken::where('family_id', $token->family_id)
            ->whereKeyNot($token->id)
            ->where('abilities', '!=', json_encode([self::REFRESH_ABILITY]))
            ->delete();

        $abilities = $user->role ? [$user->role] : ['*'];

        return $this->issuePair($user, $token->name, $abilities, $token->family_id, self::sessionStartedAt($token));
    }

    /** Ends the whole session a token belongs to (or just the token). */
    public function revokeSessionOf(PersonalAccessToken $token): int
    {
        return $token->family_id ? $this->revokeFamily($token->family_id) : (int) $token->delete();
    }

    public function revokeFamily(string $familyId): int
    {
        return PersonalAccessToken::where('family_id', $familyId)->delete();
    }

    public static function isRefreshToken(PersonalAccessToken $token): bool
    {
        return $token->abilities === [self::REFRESH_ABILITY];
    }

    /** An unused, unexpired refresh token within the 14-day session cap. */
    public static function refreshTokenUsable(PersonalAccessToken $token): bool
    {
        $started = self::sessionStartedAt($token);

        return self::isRefreshToken($token)
            && $token->expires_at !== null && $token->expires_at->isFuture()
            && $started !== null && $started->copy()->addMinutes(self::SESSION_MAX_MINUTES)->isFuture();
    }

    /**
     * Sanctum's own check covers `expires_at`. This adds the idle timeout,
     * and an absolute limit for tokens issued before expiry existed
     * (`expires_at` NULL), so no old token lives forever. Refresh tokens
     * never authenticate API requests.
     */
    public static function isStillValid(PersonalAccessToken $token): bool
    {
        if (self::isRefreshToken($token)) {
            return false;
        }

        $user = $token->tokenable instanceof User ? $token->tokenable : null;
        $policy = self::policyFor($user);
        $now = now();

        $expiresAt = $token->expires_at ?? $token->created_at?->copy()->addMinutes($policy['lifetime']);
        if ($expiresAt === null || $expiresAt->lte($now)) {
            return false;
        }

        $lastActivity = $token->last_used_at ?? $token->created_at;

        return $lastActivity !== null
            && $lastActivity->gt($now->copy()->subMinutes($policy['idle']));
    }

    /** When the token will stop working if it keeps being used. */
    public static function expiresAt(PersonalAccessToken $token): ?CarbonInterface
    {
        $user = $token->tokenable instanceof User ? $token->tokenable : null;

        return $token->expires_at
            ?? $token->created_at?->copy()->addMinutes(self::policyFor($user)['lifetime']);
    }

    /** Revokes every session of the user except, optionally, one token. */
    public function revokeAll(User $user, ?int $exceptTokenId = null): int
    {
        return $user->tokens()
            ->when($exceptTokenId, fn ($q) => $q->whereKeyNot($exceptTokenId))
            ->delete();
    }

    /** Revokes every session except the one making this request. */
    public function revokeOtherSessions(User $user): int
    {
        $current = $user->currentAccessToken();
        if (! $current instanceof PersonalAccessToken) {
            return $this->revokeAll($user);
        }

        return $user->tokens()
            ->when(
                $current->family_id,
                fn ($q) => $q->where(fn ($q) => $q->whereNull('family_id')->orWhere('family_id', '!=', $current->family_id)),
                fn ($q) => $q->whereKeyNot($current->id),
            )
            ->delete();
    }

    /**
     * @return array{access: NewAccessToken, refresh: NewAccessToken}
     */
    private function issuePair(User $user, string $label, array $abilities, string $familyId, CarbonInterface $startedAt): array
    {
        $sessionEnds = $startedAt->copy()->addMinutes(self::SESSION_MAX_MINUTES);
        $cap = fn (CarbonInterface $t) => $t->lt($sessionEnds) ? $t : $sessionEnds;

        $access = $user->createToken($label, $abilities, $cap(now()->addMinutes(self::ACCESS_TOKEN_MINUTES)));
        $refresh = $user->createToken($label, [self::REFRESH_ABILITY], $cap(now()->addMinutes(self::REFRESH_TOKEN_MINUTES)));

        foreach ([$access, $refresh] as $token) {
            $token->accessToken->forceFill(['family_id' => $familyId, 'session_started_at' => $startedAt])->save();
        }

        return ['access' => $access, 'refresh' => $refresh];
    }

    /** `session_started_at` isn't cast by Sanctum's model, so parse it here. */
    private static function sessionStartedAt(PersonalAccessToken $token): ?CarbonInterface
    {
        $raw = $token->getAttribute('session_started_at');

        return $raw ? Carbon::parse($raw) : $token->created_at;
    }

    private static function policyKey(?User $user): string
    {
        $role = $user?->role;

        return match (true) {
            in_array($role, self::GYM_ROLES, true) => 'gym',
            in_array($role, self::MEMBER_ROLES, true) => 'member',
            default => 'default',
        };
    }

    private static function deviceLabel(?string $deviceName): string
    {
        $label = Str::limit(trim(strip_tags((string) $deviceName)), 100, '');

        return $label !== '' ? $label : 'Unknown device';
    }
}
