<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\UpdateHandler;
use Illuminate\Console\Command;

/**
 * Long polling для локальной разработки (ТЗ п.94 — допускается на local/dev/staging).
 * На проде используется webhook (см. WebhookController), эта команда там не нужна.
 */
class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll';

    protected $description = 'Получать апдейты бота через long polling (только для локальной разработки)';

    public function handle(TelegramClient $telegram, UpdateHandler $updateHandler): int
    {
        $telegram->deleteWebhook();
        $this->info('Webhook удалён, слушаю апдейты через getUpdates. Ctrl+C для остановки.');

        $offset = 0;

        while (true) {
            $response = $telegram->call('getUpdates', [
                'offset' => $offset,
                'timeout' => 30,
                'allowed_updates' => ['message', 'callback_query'],
            ]);

            foreach ($response['result'] ?? [] as $update) {
                $offset = $update['update_id'] + 1;

                try {
                    $updateHandler->handle($update);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }
}
