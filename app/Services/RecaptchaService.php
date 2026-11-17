<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RecaptchaService
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /**
     * Verify a reCAPTCHA v3 token. Returns true when the token is valid and
     * scores at or above the configured minimum. When no secret key is
     * configured (e.g. local/dev environments that haven't provisioned
     * Google reCAPTCHA yet), verification is skipped so the app keeps working.
     */
    public function verify(?string $token, string $ip): bool
    {
        $secret = config('services.recaptcha.secret_key');

        if (empty($secret)) {
            Log::warning('RECAPTCHA_SECRET_KEY is not configured; skipping CAPTCHA verification.');
            return true;
        }

        if (empty($token)) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, [
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]);
        } catch (\Throwable $e) {
            Log::error('reCAPTCHA verification request failed: ' . $e->getMessage());
            return false;
        }

        if (!$response->successful()) {
            return false;
        }

        $data = $response->json();

        if (!($data['success'] ?? false)) {
            return false;
        }

        $minScore = (float) config('services.recaptcha.min_score', 0.5);

        return ($data['score'] ?? 0) >= $minScore;
    }
}
