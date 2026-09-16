<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SystemHealthCheckTest extends TestCase
{
    public function test_healthy_system_reports_success_without_alert(): void
    {
        Http::fake([
            'api.telegram.org/*getWebhookInfo*' => Http::response([
                'ok' => true,
                'result' => ['url' => 'https://example.com/webhook', 'pending_update_count' => 0],
            ]),
        ]);

        $this->artisan('system:health-check')->assertExitCode(0);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sendMessage'));
    }

    public function test_recent_webhook_error_triggers_alert_to_owner(): void
    {
        config(['services.telegram.owner_id' => 999888777]);

        Http::fake([
            'api.telegram.org/*getWebhookInfo*' => Http::response([
                'ok' => true,
                'result' => [
                    'url' => 'https://example.com/webhook',
                    'pending_update_count' => 0,
                    'last_error_date' => now()->subMinutes(5)->timestamp,
                    'last_error_message' => 'Connection timed out',
                ],
            ]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->artisan('system:health-check')->assertExitCode(1);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && (int) ($r['chat_id'] ?? 0) === 999888777
            && str_contains($r['text'] ?? '', 'Connection timed out')
        );
    }

    public function test_old_webhook_error_does_not_trigger_alert(): void
    {
        Http::fake([
            'api.telegram.org/*getWebhookInfo*' => Http::response([
                'ok' => true,
                'result' => [
                    'url' => 'https://example.com/webhook',
                    'pending_update_count' => 0,
                    'last_error_date' => now()->subHours(3)->timestamp,
                    'last_error_message' => 'Old, resolved error',
                ],
            ]),
        ]);

        $this->artisan('system:health-check')->assertExitCode(0);
    }

    public function test_missing_webhook_url_triggers_alert(): void
    {
        config(['services.telegram.owner_id' => 999888777]);

        Http::fake([
            'api.telegram.org/*getWebhookInfo*' => Http::response([
                'ok' => true,
                'result' => ['url' => '', 'pending_update_count' => 0],
            ]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->artisan('system:health-check')->assertExitCode(1);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', 'не установлен')
        );
    }

    public function test_polling_mode_skips_webhook_check_even_when_webhook_empty(): void
    {
        config(['services.telegram.mode' => 'polling', 'services.telegram.owner_id' => 999888777]);

        Http::fake([
            'api.telegram.org/*getWebhookInfo*' => Http::response([
                'ok' => true,
                'result' => ['url' => '', 'pending_update_count' => 0],
            ]),
        ]);

        $this->artisan('system:health-check')->assertExitCode(0);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sendMessage'));
    }

    public function test_large_pending_update_backlog_triggers_alert(): void
    {
        Http::fake([
            'api.telegram.org/*getWebhookInfo*' => Http::response([
                'ok' => true,
                'result' => ['url' => 'https://example.com/webhook', 'pending_update_count' => 200],
            ]),
        ]);

        $this->artisan('system:health-check')->assertExitCode(1);
    }
}
