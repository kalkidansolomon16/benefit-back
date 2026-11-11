<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** Development only: never use in production (codes end up in the log). */
class LogSmsSender implements SmsSender
{
    public function available(): bool
    {
        return true;
    }

    public function send(string $phone, string $message): void
    {
        Log::debug('sms.log_driver', ['to' => $phone, 'message' => $message]);
    }
}
