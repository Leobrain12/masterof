<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

/**
 * Управление production-вебхуком (ТЗ п.93). Для локальной разработки используй telegram:poll.
 */
class TelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook {action=info : set|delete|info} {url? : Публичный HTTPS-адрес для set}';

    protected $description = 'Установить, удалить или проверить Telegram-вебхук';

    public function handle(TelegramClient $telegram): int
    {
        $action = $this->argument('action');

        $result = match ($action) {
            'set' => $this->setWebhook($telegram),
            'delete' => $telegram->deleteWebhook(),
            'info' => $telegram->getWebhookInfo(),
            default => null,
        };

        if ($result === null) {
            $this->error("Неизвестное действие: {$action}. Используй set|delete|info.");

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function setWebhook(TelegramClient $telegram): array
    {
        $url = $this->argument('url');
        $secret = config('services.telegram.webhook_secret');

        if (! $url) {
            $this->error('Укажи адрес: php artisan telegram:webhook set https://example.com/api/telegram/webhook');

            return ['ok' => false];
        }

        // ТЗ п.93: production — только HTTPS. Telegram и сам это требует, но лучше
        // понятная ошибка здесь, чем расшифровывать отказ Bot API постфактум.
        if (! str_starts_with($url, 'https://')) {
            $this->error("Вебхук обязан быть на HTTPS, получено: {$url}");

            return ['ok' => false];
        }

        if (! $secret) {
            $this->error('TELEGRAM_WEBHOOK_SECRET не задан в .env');

            return ['ok' => false];
        }

        return $telegram->setWebhook($url, $secret);
    }
}
