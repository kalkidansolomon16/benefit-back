<?php

namespace App\Services\Sms;

class NullSmsSender implements SmsSender
{
    public function available(): bool
    {
        return false;
    }

    public function send(string $phone, string $message): void
    {
        throw new \RuntimeException('SMS is not configured (SMS_DRIVER=none).');
    }
}
