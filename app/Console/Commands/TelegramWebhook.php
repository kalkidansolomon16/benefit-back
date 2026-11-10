<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Show where Telegram delivers the bot's messages, and point it at this server.
 *
 * Telegram sends a bot's updates to ONE webhook URL. If another server (an old
 * deployment, or a PC that ran `telegram:poll`) took the bot over, /link codes
 * created on this server are checked against that server's database and always
 * come back "Invalid or expired".
 *
 *   php artisan telegram:webhook          # show the current webhook
 *   php artisan telegram:webhook --set    # point the bot at this server
 */
class TelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook
        {--set : Point the bot at this server}
        {--url= : Full webhook URL (default: APP_URL + /api/v1/telegram/webhook)}';

    protected $description = 'Show or set the Telegram bot webhook';

    public function handle(): int
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            $this->error('TELEGRAM_BOT_TOKEN is not set in .env.');
            return self::FAILURE;
        }
        $api = "https://api.telegram.org/bot{$token}";

        $url = $this->option('url')
            ?: rtrim((string) config('app.url'), '/') . '/api/v1/telegram/webhook';

        if ($this->option('set')) {
            $params = [
                'url'                  => $url,
                'allowed_updates'      => json_encode(['message', 'callback_query']),
                'drop_pending_updates' => 'false',
            ];
            $secret = (string) config('services.telegram.webhook_secret');
            if ($secret !== '') {
                $params['secret_token'] = $secret;
            }

            $res = Http::asForm()->post("{$api}/setWebhook", $params);
            if (!$res->json('ok')) {
                $this->error('Telegram refused the webhook: ' . ($res->json('description') ?? $res->body()));
                return self::FAILURE;
            }
            $this->info("Webhook set to {$url}" . ($secret !== '' ? ' (with secret token).' : '.'));
        }

        $info = Http::get("{$api}/getWebhookInfo")->json('result') ?? [];
        $current = $info['url'] ?? '';

        $this->line('Current webhook:  ' . ($current !== '' ? $current : '(none: someone is polling the bot, e.g. telegram:poll)'));
        $this->line('Pending updates:  ' . ($info['pending_update_count'] ?? 0));
        if (!empty($info['last_error_message'])) {
            $this->warn('Last error:       ' . $info['last_error_message']
                . (isset($info['last_error_date']) ? ' (' . date('Y-m-d H:i', $info['last_error_date']) . ')' : ''));
        }

        if ($current !== $url) {
            $this->warn("This server expects: {$url}");
            $this->warn('Run  php artisan telegram:webhook --set  to point the bot here.');
        } else {
            $this->info('The bot is connected to this server.');
        }

        return self::SUCCESS;
    }
}
