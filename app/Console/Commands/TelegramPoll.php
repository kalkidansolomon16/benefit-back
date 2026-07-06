<?php

namespace App\Console\Commands;

use App\Http\Controllers\TelegramController;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramPoll extends Command
{
    protected $signature   = 'telegram:poll';
    protected $description = 'Poll Telegram for updates (local development only)';

    public function handle(TelegramService $telegram): void
    {
        $token   = config('services.telegram.bot_token');
        $apiBase = "https://api.telegram.org/bot{$token}";
        $offset  = 0;

        // Delete any registered webhook so polling works
        $del = Http::withoutVerifying()->post("{$apiBase}/deleteWebhook");
        if ($del->successful()) {
            $this->info('Webhook removed. Polling for updates… (Ctrl+C to stop)');
        } else {
            $this->warn('Could not remove webhook: ' . $del->body());
        }

        $controller = app(TelegramController::class);

        while (true) {
            try {
                $response = Http::withoutVerifying()
                    ->timeout(35)
                    ->get("{$apiBase}/getUpdates", [
                        'offset'  => $offset,
                        'timeout' => 30,
                        'allowed_updates' => ['message', 'callback_query'],
                    ]);

                if (! $response->successful()) {
                    $this->warn('getUpdates failed: ' . $response->body());
                    sleep(3);
                    continue;
                }

                $updates = $response->json('result', []);

                foreach ($updates as $update) {
                    $offset = $update['update_id'] + 1;

                    $request = Request::create(
                        '/api/v1/telegram/webhook',
                        'POST',
                        [],
                        [],
                        [],
                        ['CONTENT_TYPE' => 'application/json'],
                        json_encode($update)
                    );
                    $request->setJson(new \Symfony\Component\HttpFoundation\ParameterBag($update));

                    $this->line('[' . now()->toTimeString() . '] Update #' . $update['update_id']);
                    $controller->webhook($request);
                }
            } catch (\Throwable $e) {
                $this->error('Error: ' . $e->getMessage());
                Log::error('telegram:poll error: ' . $e->getMessage());
                sleep(3);
            }
        }
    }
}
