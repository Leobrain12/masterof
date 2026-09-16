<?php

namespace Tests\Feature;

use App\Services\Telegram\TelegramClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TELEGRAM_PROXY_HOST/PORT/TYPE (ТЗ — реальный случай: хостинг без прямого
 * доступа до api.telegram.org, см. vault/Решения.md). Http::fake() не даёт
 * заглянуть в Guzzle-опцию "proxy" самого запроса — здесь проверяется, что
 * включение прокси в конфиге не ломает обычный вызов API, не сам факт того,
 * что cURL реально пойдёт через прокси (для этого нужен реальный прокси-сервер).
 */
class TelegramClientProxyTest extends TestCase
{
    public function test_client_works_normally_without_proxy_configured(): void
    {
        config(['services.telegram.proxy' => ['host' => null, 'port' => null, 'type' => 'socks5h']]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'test_bot']])]);

        $client = new TelegramClient('test-token');
        $result = $client->getMe();

        $this->assertTrue($result['ok']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'getMe'));
    }

    public function test_client_still_calls_correct_url_with_proxy_configured(): void
    {
        config(['services.telegram.proxy' => ['host' => '144.126.197.184', 'port' => '1080', 'type' => 'socks5h']]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'test_bot']])]);

        $client = new TelegramClient('test-token');
        $result = $client->getMe();

        $this->assertTrue($result['ok']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'bottest-token/getMe'));
    }

    public function test_unknown_proxy_type_falls_back_to_socks5h(): void
    {
        config(['services.telegram.proxy' => ['host' => '144.126.197.184', 'port' => '1080', 'type' => 'SOCKS']]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        // Не должно бросать исключение из-за незнакомого написания типа
        // ("SOCKS" из старого шаблона переменных, не "socks5h").
        $client = new TelegramClient('test-token');
        $result = $client->getMe();

        $this->assertTrue($result['ok']);
    }
}
