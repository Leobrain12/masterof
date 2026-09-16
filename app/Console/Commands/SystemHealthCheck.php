<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Базовый мониторинг (ТЗ п.104): вебхук, очередь, БД. Тихий при здоровой
 * системе, алерт владельцу в Telegram при проблеме — не хочется заводить
 * полноценный Sentry-дашборд ради одного разработчика, но и молчать, если
 * вебхук отвалился, нельзя. Sentry (если настроен, см. .env.example) получает
 * то же самое через report() — на случай, если в будущем команда вырастет
 * и понадобится дашборд, а не только личные Telegram-уведомления.
 *
 * TelegramClient создаётся лениво и намеренно не через конструктор: если
 * TELEGRAM_BOT_TOKEN ещё не настроен (например только разворачиваем прод),
 * это само по себе диагностируемая проблема, а не повод уронить всю команду —
 * тогда просто нечем отправить алерт, но остальные проверки (БД, очередь)
 * всё равно должны отработать и попасть в лог/консоль.
 */
class SystemHealthCheck extends Command
{
    protected $signature = 'system:health-check';

    protected $description = 'Проверить вебхук, очередь и БД; уведомить владельца в Telegram при проблеме';

    /**
     * Апдейт считается зависшим, если пришёл >15 минут назад и всё ещё не обработан.
     */
    private const WEBHOOK_ERROR_WINDOW_MINUTES = 15;

    private const PENDING_UPDATES_THRESHOLD = 50;

    public function handle(): int
    {
        $telegram = $this->makeTelegramClient();

        $problems = [];
        $problems = array_merge($problems, $this->checkWebhook($telegram));
        $problems = array_merge($problems, $this->checkQueue());
        $problems = array_merge($problems, $this->checkDatabase());

        if ($problems === []) {
            $this->info('Все проверки пройдены.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        if ($telegram) {
            $this->alertOwner($telegram, $problems);
        }

        return self::FAILURE;
    }

    private function makeTelegramClient(): ?TelegramClient
    {
        try {
            return app(TelegramClient::class);
        } catch (Throwable $e) {
            $this->error("TELEGRAM_BOT_TOKEN не настроен — пропускаю проверку вебхука и алерт: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function checkWebhook(?TelegramClient $telegram): array
    {
        if (! $telegram) {
            return [];
        }

        // В polling-режиме telegram:poll сам удаляет вебхук при старте (ТЗ п.94,
        // реальный случай — сеть блокирует входящие от Telegram, см. TelegramPoll
        // и vault/Решения.md) — пустой url тут ожидаемое состояние, а не поломка.
        // Без этой проверки health-check слал бы ложный алерт каждые 15 минут.
        if (config('services.telegram.mode') === 'polling') {
            return [];
        }

        try {
            $info = $telegram->getWebhookInfo()['result'] ?? null;
        } catch (Throwable $e) {
            report($e);

            return ["Не удалось получить статус вебхука: {$e->getMessage()}"];
        }

        if (! $info) {
            return ['Telegram не вернул информацию о вебхуке.'];
        }

        $problems = [];

        if (empty($info['url'])) {
            $problems[] = 'Вебхук не установлен (пустой url) — бот не получает апдейты.';
        }

        if (! empty($info['last_error_date'])) {
            // absolute: true — по умолчанию Carbon 3 возвращает знаковую разницу
            // (отрицательную для прошлого), из-за чего "3 часа назад" без этого
            // параметра проходило бы проверку "<= 15 минут" (см. vault/Фазы/Фаза 07).
            $minutesAgo = (int) round(now()->diffInMinutes(now()->createFromTimestamp($info['last_error_date']), true));

            if ($minutesAgo <= self::WEBHOOK_ERROR_WINDOW_MINUTES) {
                $message = $info['last_error_message'] ?? 'без деталей';
                $problems[] = "Ошибка вебхука {$minutesAgo} мин. назад: {$message}";
            }
        }

        if (($info['pending_update_count'] ?? 0) > self::PENDING_UPDATES_THRESHOLD) {
            $problems[] = "Очередь необработанных апдейтов Telegram: {$info['pending_update_count']}.";
        }

        return $problems;
    }

    /**
     * Redis проверяется, только если очередь реально настроена на него —
     * QUEUE_CONNECTION=database (по умолчанию, пока нет реальной CRM и объём
     * задач небольшой, см. vault/Решения.md) работает поверх той же БД, что
     * уже проверяет checkDatabase(), отдельной проверки не требует. Пинговать
     * Redis, когда очередь на нём даже не работает, — проверка не того, что
     * реально используется.
     *
     * @return list<string>
     */
    private function checkQueue(): array
    {
        if (config('queue.default') !== 'redis') {
            return [];
        }

        try {
            Redis::connection()->ping();
        } catch (Throwable $e) {
            report($e);

            return ["Redis/очередь недоступны: {$e->getMessage()}"];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function checkDatabase(): array
    {
        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            report($e);

            return ["БД недоступна: {$e->getMessage()}"];
        }

        return [];
    }

    /**
     * @param  list<string>  $problems
     */
    private function alertOwner(TelegramClient $telegram, array $problems): void
    {
        $ownerId = config('services.telegram.owner_id');

        if (! $ownerId) {
            return;
        }

        $text = "⚠️ Проверка системы нашла проблемы:\n".implode("\n", array_map(fn ($p) => "— {$p}", $problems));

        $telegram->sendMessage((int) $ownerId, $text);
    }
}
