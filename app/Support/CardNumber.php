<?php

namespace App\Support;

use App\Models\Employee;

/**
 * FitAccess card numbers: 12 digits, the last one a Luhn check digit so a
 * mistyped digit is caught before any lookup. Numbers are random (not
 * sequential) so they cannot be guessed from one another.
 */
class CardNumber
{
    public const LENGTH = 12;

    public static function generate(): string
    {
        do {
            $body = (string) random_int(1, 9);
            for ($i = 1; $i < self::LENGTH - 1; $i++) {
                $body .= random_int(0, 9);
            }
            $number = $body . self::checkDigit($body);
        } while (Employee::withTrashed()->where('card_number', $number)->exists());

        return $number;
    }

    /** Strips spaces and dashes; returns null when it is not a well-formed number. */
    public static function normalize(?string $input): ?string
    {
        $digits = preg_replace('/[\s-]+/', '', (string) $input);

        if (!preg_match('/^\d{' . self::LENGTH . '}$/', $digits)) {
            return null;
        }

        return self::checkDigit(substr($digits, 0, -1)) === substr($digits, -1) ? $digits : null;
    }

    /** "123456789012" -> "1234 5678 9012" */
    public static function format(?string $number): ?string
    {
        return $number ? trim(chunk_split($number, 4, ' ')) : null;
    }

    private static function checkDigit(string $body): string
    {
        $sum = 0;
        $double = true; // rightmost digit of the body is doubled
        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $d = (int) $body[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) $d -= 9;
            }
            $sum += $d;
            $double = !$double;
        }

        return (string) ((10 - $sum % 10) % 10);
    }
}
