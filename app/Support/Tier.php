<?php

namespace App\Support;

/**
 * The one place that decides packages and which gyms they open.
 *
 * Packages are stored under several spellings: plans keep the admin-typed name
 * ("Fit Basic Plus"), gyms keep a key ("basic_plus"), and the top package is
 * "premium" in newer data but "platinum" / "gold" in older data. Everything is
 * compared through canonical() so every spelling means the same package.
 *
 * Higher packages include the lower ones (client rule):
 *   Fit Basic       → Basic gyms
 *   Fit Basic Plus  → Basic + Basic Plus gyms
 *   Fit Premium     → all gyms
 */
final class Tier
{
    public const BASIC      = 'basic';
    public const BASIC_PLUS = 'basic_plus';
    public const PREMIUM    = 'premium';

    private const RANK = [self::BASIC => 1, self::BASIC_PLUS => 2, self::PREMIUM => 3];

    /** "Fit Premium", "fit_platinum", "gold", "premium" … → basic | basic_plus | premium */
    public static function canonical(?string $tier): string
    {
        $t = strtolower(trim((string) $tier));
        return match (true) {
            (bool) preg_match('/platinum|premium|gold/', $t) => self::PREMIUM,
            (bool) preg_match('/plus|\+/', $t)               => self::BASIC_PLUS,
            default                                          => self::BASIC,
        };
    }

    /** Employee level (what HR's package choice is saved as) → package. */
    public static function forLevel(?string $level): string
    {
        return match ($level) {
            'chief'    => self::PREMIUM,
            'director' => self::BASIC_PLUS,
            default    => self::BASIC,
        };
    }

    public static function rank(?string $tier): int
    {
        return self::RANK[self::canonical($tier)];
    }

    /** Can a member on $memberTier use a gym of $gymTier? */
    public static function allows(?string $memberTier, ?string $gymTier): bool
    {
        return self::rank($memberTier) >= self::rank($gymTier);
    }

    /**
     * Raw gyms.tier values a package opens, for whereIn() queries
     * (both spellings of the top tier are included).
     *
     * @return string[]
     */
    public static function gymTiersFor(?string $memberTier): array
    {
        return match (self::canonical($memberTier)) {
            self::PREMIUM    => ['basic', 'basic_plus', 'premium', 'platinum'],
            self::BASIC_PLUS => ['basic', 'basic_plus'],
            default          => ['basic'],
        };
    }
}
