<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    private const ISSUER = 'FitAccess';

    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA();
    }

    public function generateSecretKey(): string
    {
        return $this->engine->generateSecretKey();
    }

    /**
     * Renders the otpauth:// provisioning URI as an inline SVG data URI,
     * scannable by Google Authenticator / Microsoft Authenticator / Authy.
     */
    public function getQrCodeDataUri(string $accountEmail, string $secret): string
    {
        $url = $this->engine->getQRCodeUrl(self::ISSUER, $accountEmail, $secret);

        $renderer = new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd());
        $svg      = (new Writer($renderer))->writeString($url);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public function verifyKey(string $secret, string $code): bool
    {
        // Reject anything that isn't a plain 6-digit code before handing it
        // to the TOTP engine (also guards against overly long input).
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        return $this->engine->verifyKey($secret, $code, 1);
    }

    /**
     * Generates a fresh batch of one-time recovery codes. Returns both the
     * plaintext codes (shown once to the user) and their hashed form (to store).
     *
     * @return array{plain: string[], hashed: string[]}
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $plain  = [];
        $hashed = [];

        for ($i = 0; $i < $count; $i++) {
            $code     = strtoupper(Str::random(5) . '-' . Str::random(5));
            $plain[]  = $code;
            $hashed[] = bcrypt($code);
        }

        return ['plain' => $plain, 'hashed' => $hashed];
    }
}
