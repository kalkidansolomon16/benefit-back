<?php

namespace App\Services;

use App\Mail\FitAccessNotificationMail;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * 6-digit confirmation codes for v2 account actions (linking a company
 * membership, changing email or phone). Codes live in the cache as an
 * HMAC only, expire after 10 minutes and allow 5 wrong tries.
 */
class OneTimeCodeService
{
    public const TTL_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly SmsSender $sms) {}

    /** True when codes can be sent by SMS (a real provider is configured). */
    public function smsAvailable(): bool
    {
        return $this->sms->available();
    }

    /**
     * Creates a code for ($purpose, $subject) and sends it.
     * $subject ties the code to what it confirms (e.g. "user:5|email:new@x").
     */
    public function send(string $purpose, string $subject, string $channel, string $destination, string $recipientName, string $what): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->key($purpose, $subject), [
            'hash' => $this->hash($code),
            'attempts' => 0,
        ], now()->addMinutes(self::TTL_MINUTES));

        if ($channel === 'sms') {
            $this->sms->send($destination, "FitAccess code: {$code}. It confirms {$what} and expires in ".self::TTL_MINUTES.' minutes. Never share it.');
        } else {
            Mail::to($destination)->send(new FitAccessNotificationMail(
                recipientName: $recipientName,
                emailSubject: 'Your FitAccess confirmation code',
                heading: $code,
                message: "Use this code to confirm {$what}. It expires in ".self::TTL_MINUTES." minutes. If you didn't ask for it, you can ignore this email. FitAccess will never ask you for this code.",
                buttonText: 'Open FitAccess',
                color: '#2EB84B',
            ));
        }

        Log::info('otp.sent', ['purpose' => $purpose, 'channel' => $channel]);
    }

    /** 'ok' | 'wrong' | 'expired' (no code, expired, or too many tries). */
    public function verify(string $purpose, string $subject, string $code): string
    {
        $key = $this->key($purpose, $subject);
        $entry = Cache::get($key);
        if (!$entry || $entry['attempts'] >= self::MAX_ATTEMPTS) {
            return 'expired';
        }

        if (hash_equals($entry['hash'], $this->hash($code))) {
            Cache::forget($key);

            return 'ok';
        }

        $entry['attempts']++;
        Cache::put($key, $entry, now()->addMinutes(self::TTL_MINUTES));

        return $entry['attempts'] >= self::MAX_ATTEMPTS ? 'expired' : 'wrong';
    }

    private function key(string $purpose, string $subject): string
    {
        return 'otp:'.$purpose.':'.hash('sha256', $subject);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', 'otp:'.$code, (string) config('app.key'));
    }

    /** "+251911234412" → "+251 9•• ••• 412"; "abebe@x.com" → "a••••••@x.com". */
    public static function mask(string $destination): string
    {
        if (str_contains($destination, '@')) {
            [$local, $domain] = explode('@', $destination, 2);

            return mb_substr($local, 0, 1).'••••••@'.$domain;
        }
        $digits = preg_replace('/\D/', '', $destination);
        if (strlen($digits) < 6) {
            return '••••••';
        }

        return (str_starts_with($destination, '+') ? '+' : '').substr($digits, 0, 3).' '.substr($digits, 3, 1).'•• ••• '.substr($digits, -3);
    }
}
