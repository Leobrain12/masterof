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

    /**
     * @return string
     */
    private function resolveProxyOf(TelegramClient $client)
    {
        $method = new \ReflectionMethod($client, 'resolveProxy');

        return $method->invoke($client);
    }

    public function test_proxy_url_embeds_urlencoded_credentials(): void
    {
        config(['services.telegram.proxy' => [
            'host' => '209.50.160.133',
            'port' => '3129',
            'type' => 'http',
            'username' => '075951frpvgu',
            'password' => 'nlhkm975clzq54i',
        ]]);

        $proxy = $this->resolveProxyOf(new TelegramClient('test-token'));

        $this->assertSame('http://075951frpvgu:nlhkm975clzq54i@209.50.160.133:3129', $proxy);
    }

    public function test_proxy_credential_with_special_characters_is_urlencoded(): void
    {
        config(['services.telegram.proxy' => [
            'host' => 'proxy.example.com',
            'port' => '1080',
            'type' => 'socks5h',
            'username' => 'user@name',
            'password' => 'p@ss:word/1',
        ]]);

        $proxy = $this->resolveProxyOf(new TelegramClient('test-token'));

        $this->assertSame('socks5h://user%40name:p%40ss%3Aword%2F1@proxy.example.com:1080', $proxy);
    }

    public function test_proxy_without_credentials_has_no_userinfo(): void
    {
        config(['services.telegram.proxy' => [
            'host' => '144.126.197.184',
            'port' => '1080',
            'type' => 'socks5h',
            'username' => null,
            'password' => null,
        ]]);

        $proxy = $this->resolveProxyOf(new TelegramClient('test-token'));

        $this->assertSame('socks5h://144.126.197.184:1080', $proxy);
    }
}
