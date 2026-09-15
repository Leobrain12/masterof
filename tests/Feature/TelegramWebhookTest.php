<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_URL = '/api/telegram/webhook';

    private function messageUpdate(int $telegramUserId, string $text): array
    {
        return [
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => 1,
                'from' => ['id' => $telegramUserId, 'is_bot' => false, 'first_name' => 'Test'],
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                'text' => $text,
            ],
        ];
    }

    public function test_request_without_valid_secret_is_rejected(): void
    {
        $response = $this->postJson(self::WEBHOOK_URL, $this->messageUpdate(111, '/start'));

        $response->assertStatus(403);
    }

    public function test_unknown_telegram_user_is_denied_access(): void
    {
        Http::fake();

        $response = $this->postJson(
            self::WEBHOOK_URL,
            $this->messageUpdate(999999, '/start'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret']
        );

        $response->assertOk();

        Http::assertSent(fn ($request) => $request['text'] === "Доступ к системе не предоставлен.\nОбратитесь к администратору."
        );

        $this->assertDatabaseCount('users', 0);
    }

    public function test_inactive_user_is_denied_access(): void
    {
        Http::fake();

        $user = User::factory()->master()->create(['is_active' => false]);

        $this->postJson(
            self::WEBHOOK_URL,
            $this->messageUpdate($user->telegram_user_id, '/start'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret']
        )->assertOk();

        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Доступ к системе не предоставлен'));
    }

    public function test_active_admin_gets_welcome_message_with_admin_menu(): void
    {
        Http::fake();

        $admin = User::factory()->admin()->create(['name' => 'Алина']);

        $this->postJson(
            self::WEBHOOK_URL,
            $this->messageUpdate($admin->telegram_user_id, '/start'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret']
        )->assertOk();

        Http::assertSent(function ($request) {
            $keyboard = json_decode($request['reply_markup'] ?? '{}', true);
            $buttons = collect($keyboard['keyboard'] ?? [])->flatten()->all();

            return str_contains($request['text'] ?? '', 'Алина')
                && in_array('Новая заявка', $buttons, true)
                && ! in_array('Пользователи', $buttons, true);
        });
    }

    public function test_superadmin_menu_includes_users_button(): void
    {
        Http::fake();

        $owner = User::factory()->superadmin()->create();

        $this->postJson(
            self::WEBHOOK_URL,
            $this->messageUpdate($owner->telegram_user_id, '/start'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret']
        )->assertOk();

        Http::assertSent(function ($request) {
            $keyboard = json_decode($request['reply_markup'] ?? '{}', true);
            $buttons = collect($keyboard['keyboard'] ?? [])->flatten()->all();

            return in_array('Пользователи', $buttons, true);
        });
    }

    public function test_master_menu_has_no_admin_buttons(): void
    {
        Http::fake();

        $master = User::factory()->master()->create();

        $this->postJson(
            self::WEBHOOK_URL,
            $this->messageUpdate($master->telegram_user_id, '/start'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret']
        )->assertOk();

        Http::assertSent(function ($request) {
            $keyboard = json_decode($request['reply_markup'] ?? '{}', true);
            $buttons = collect($keyboard['keyboard'] ?? [])->flatten()->all();

            return in_array('Моя статистика', $buttons, true)
                && ! in_array('Новая заявка', $buttons, true);
        });
    }

    public function test_unmapped_button_gets_stub_reply(): void
    {
        Http::fake();

        $admin = User::factory()->admin()->create();

        $this->postJson(
            self::WEBHOOK_URL,
            $this->messageUpdate($admin->telegram_user_id, 'Мастера'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret']
        )->assertOk();

        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'в одной из следующих фаз')
        );
    }
}
