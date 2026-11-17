<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StrongPassword implements ValidationRule
{
    private const MIN_LENGTH = 12;

    /**
     * Common passwords/patterns rejected outright, no network call needed.
     * Covers exactly the kind of credential this rule exists to stop
     * (e.g. the literal password "password" used on a super-admin account).
     */
    private const BLOCKLIST = [
        'password', 'password1', 'password123', 'passw0rd', 'p@ssword', 'p@ssw0rd',
        '12345678', '123456789', '1234567890', 'qwertyuiop', 'qwerty123',
        'admin123', 'administrator', 'letmein123', 'welcome123', 'welcome1',
        'changeme', 'changeme123', 'fitaccess', 'fitaccess123', 'superadmin',
        'iloveyou', 'sunshine', 'princess', 'football', 'baseball', 'trustno1',
        'abc123456', 'zxcvbnm', 'asdfghjkl',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) < self::MIN_LENGTH) {
            $fail('The :attribute must be at least ' . self::MIN_LENGTH . ' characters.');
            return;
        }
        if (! preg_match('/[A-Z]/', $value)) {
            $fail('The :attribute must contain at least one uppercase letter.');
            return;
        }
        if (! preg_match('/[a-z]/', $value)) {
            $fail('The :attribute must contain at least one lowercase letter.');
            return;
        }
        if (! preg_match('/[0-9]/', $value)) {
            $fail('The :attribute must contain at least one number.');
            return;
        }
        if (! preg_match('/[^A-Za-z0-9]/', $value)) {
            $fail('The :attribute must contain at least one special character.');
            return;
        }
        if (in_array(strtolower(preg_replace('/[^a-z0-9]/i', '', $value)), self::BLOCKLIST, true)) {
            $fail('That password is too common. Please choose a different one.');
            return;
        }
        if ($this->isCompromised($value)) {
            $fail('That password has appeared in a known data breach. Please choose a different one.');
        }
    }

    /**
     * Checks the password against the "Pwned Passwords" breach database via
     * k-anonymity (only the first 5 chars of the SHA-1 hash ever leave the
     * server, so the real password is never transmitted or logged). Fails
     * open (allows the password) if the service is unreachable, so a
     * third-party outage can never block account creation or password resets.
     */
    private function isCompromised(string $value): bool
    {
        $sha1   = strtoupper(sha1($value));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        try {
            $response = Http::timeout(3)->get("https://api.pwnedpasswords.com/range/{$prefix}");
        } catch (\Throwable $e) {
            Log::warning('Pwned Passwords check failed, skipping: ' . $e->getMessage());
            return false;
        }

        if (!$response->successful()) {
            return false;
        }

        foreach (explode("\r\n", $response->body()) as $line) {
            [$candidateSuffix] = explode(':', $line);
            if (hash_equals($suffix, trim($candidateSuffix))) {
                return true;
            }
        }

        return false;
    }
}
