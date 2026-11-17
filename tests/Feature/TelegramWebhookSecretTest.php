<?php

namespace Tests\Feature;

use Tests\TestCase;

/** With TELEGRAM_WEBHOOK_SECRET set, only updates carrying Telegram's secret header are processed. */
class TelegramWebhookSecretTest extends TestCase
{
    public function test_webhook_rejects_updates_without_the_secret(): void
    {
        config(['services.telegram.webhook_secret' => 'tg-secret-123']);

        $this->postJson('/api/v1/telegram/webhook', ['update_id' => 1])->assertStatus(403);
        $this->postJson('/api/v1/telegram/webhook', ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertStatus(403);
        $this->postJson('/api/v1/telegram/webhook', ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => 'tg-secret-123'])
            ->assertOk()->assertJsonPath('ok', true);
    }

    public function test_webhook_still_works_when_no_secret_is_configured(): void
    {
        config(['services.telegram.webhook_secret' => '']);
        $this->postJson('/api/v1/telegram/webhook', ['update_id' => 1])->assertOk();
    }
}
