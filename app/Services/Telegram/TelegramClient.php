<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Тонкая обёртка над Telegram Bot API.
 *
 * Осознанно без SDK-пакета: методов, которые нужны боту, немного,
 * а прямой HTTP-вызов проще диагностировать и не тянет версионный дрейф
 * стороннего пакета (ТЗ п.3 — «иной подход на усмотрение команды» допускается).
 */
class TelegramClient
{
    private string $token;

    private string $baseUrl;

    /**
     * Реальный случай: некоторые хостинги не пускают наружу напрямую до
     * api.telegram.org (см. vault/Решения.md) — TELEGRAM_PROXY_HOST/PORT/TYPE
     * в .env, пусто по умолчанию.
     */
    private ?string $proxy;

    public function __construct(?string $token = null)
    {
        $token ??= config('services.telegram.bot_token');

        if (! $token) {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN не задан в .env');
        }

        $this->token = $token;
        $this->baseUrl = "https://api.telegram.org/bot{$token}/";
        $this->proxy = $this->resolveProxy();
    }

    private function resolveProxy(): ?string
    {
        $host = config('services.telegram.proxy.host');
        $port = config('services.telegram.proxy.port');

        if (! $host || ! $port) {
            return null;
        }

        // socks5h (не socks5) — резолвит DNS через сам прокси, не локально:
        // локальный DNS мог быть недоступен/подсанкционирован ровно там же,
        // где недоступен прямой выход до api.telegram.org.
        $scheme = match (strtolower((string) config('services.telegram.proxy.type', 'socks5h'))) {
            'http', 'https' => 'http',
            'socks4' => 'socks4',
            default => 'socks5h',
        };

        $username = config('services.telegram.proxy.username');
        $password = config('services.telegram.proxy.password');

        // userinfo в самом URL — так cURL передаёт логин/пароль для прокси
        // (Proxy-Authorization). urlencode — на случай спецсимволов в пароле,
        // которые иначе разъехались бы с разбором URL.
        $auth = $username ? rawurlencode($username).':'.rawurlencode((string) $password).'@' : '';

        return "{$scheme}://{$auth}{$host}:{$port}";
    }

    /**
     * 35s — не произвольное число: getUpdates в long-polling режиме (TelegramPoll)
     * просит Telegram держать соединение открытым до 30с ('timeout' => 30). Laravel
     * Http-клиент по умолчанию сам обрывает запрос через 30с — гонка, которую клиент
     * иногда проигрывает буквально на миллисекунды ("cURL error 28: Operation timed
     * out after 30001 milliseconds"), и это НЕ ловится ($response->failed() тут не
     * при чём — соединение оборвано, ответа нет вообще). Раньше это ронялось наружу
     * необработанным и валило весь процесс (контейнер уходил в exited) — реальный
     * инцидент на проде при первом живом запуске polling. 35с — запас над 30с
     * long-poll, с большинством остальных вызовов (sendMessage и т.п.) не связан.
     */
    private function http(): PendingRequest
    {
        $request = Http::asJson()->timeout(35);

        return $this->proxy ? $request->withOptions(['proxy' => $this->proxy]) : $request;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        $response = $this->http()->post($this->baseUrl.$method, $params);

        if ($response->failed() || ($response->json('ok') !== true)) {
            report(new RuntimeException(
                "Telegram API {$method} failed: ".$response->body()
            ));
        }

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): array
    {
        return $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $replyMarkup ? json_encode($replyMarkup, JSON_UNESCAPED_UNICODE) : null,
        ], fn ($v) => $v !== null));
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): array
    {
        return $this->call('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ]));
    }

    public function setWebhook(string $url, string $secretToken): array
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => ['message', 'callback_query'],
        ]);
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook');
    }

    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo');
    }

    public function getMe(): array
    {
        return $this->call('getMe');
    }

    /**
     * Скачивает файл по file_id (ТЗ п.54: Telegram update → metadata → скачать →
     * сохранить в private storage). Bot API ограничивает скачивание 20 МБ на файл —
     * это проверяется раньше, по file_size из самого апдейта, до вызова этого метода.
     *
     * @return array{bytes: string, file_path: string}|null  null — файл недоступен/не найден
     */
    public function downloadFile(string $fileId): ?array
    {
        $info = $this->call('getFile', ['file_id' => $fileId]);
        $filePath = $info['result']['file_path'] ?? null;

        if (! $filePath) {
            return null;
        }

        $response = $this->http()->get("https://api.telegram.org/file/bot{$this->token}/{$filePath}");

        if ($response->failed()) {
            report(new RuntimeException("Telegram file download failed: {$filePath}"));

            return null;
        }

        return ['bytes' => $response->body(), 'file_path' => $filePath];
    }
}
