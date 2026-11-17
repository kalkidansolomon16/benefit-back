<?php

namespace App\Services\Sms;

/**
 * Sends SMS. No Ethiopian SMS provider is configured yet (SMS_DRIVER):
 *   none – SMS is unavailable; endpoints that need it say so (default)
 *   log  – writes the message to the log (local development only)
 * Add a provider (e.g. AfroMessage or Ethio Telecom) as another driver.
 */
interface SmsSender
{
    public function available(): bool;

    public function send(string $phone, string $message): void;
}
