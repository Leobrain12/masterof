<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UserManagementScreenTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_URL = '/api/telegram/webhook';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Http::fake();
    }

    private function postMessage(int $telegramUserId, string $text): void
    {
        $this->postJson(self::WEBHOOK_URL, [
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => 1,
                'from' => ['id' => $telegramUserId, 'is_bot' => false, 'first_name' => 'Test'],
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                'text' => $text,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }

    private function postCallback(int $telegramUserId, string $data): void
    {
        $this->postJson(self::WEBHOOK_URL, [
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => (string) random_int(1, PHP_INT_MAX),
                'from' => ['id' => $telegramUserId, 'is_bot' => false, 'first_name' => 'Test'],
                'message' => ['chat' => ['id' => $telegramUserId, 'type' => 'private'], 'message_id' => 1],
                'data' => $data,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }

    public function test_superadmin_sees_roster_grouped_by_role(): void
    {
        $superadmin = User::factory()->superadmin()->create(['telegram_user_id' => 1101, 'name' => 'Даниил']);
        $admin = User::factory()->admin()->create(['telegram_user_id' => 1102, 'name' => 'Пётр']);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 1103, 'name' => 'Ахмед']);

        $this->postMessage($superadmin->telegram_user_id, 'Пользователи');

        foreach ([$superadmin, $admin, $masterUser] as $u) {
            Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $superadmin->telegram_user_id
                && str_contains($r['text'] ?? '', $u->name)
                && str_contains($r['reply_markup'] ?? '', "users:toggle:{$u->id}")
            );
        }
    }

    public function test_admin_cannot_open_users_screen(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 1104]);

        $this->postMessage($admin->telegram_user_id, 'Пользователи');

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($r['text'] ?? '', 'следующих фаз')
        );
        Http::assertNotSent(fn ($r) => str_contains($r['reply_markup'] ?? '', 'users:toggle'));
    }

    public function test_master_cannot_open_users_screen(): void
    {
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 1105]);

        $this->postMessage($masterUser->telegram_user_id, 'Пользователи');

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $masterUser->telegram_user_id
            && str_contains($r['text'] ?? '', 'следующих фаз')
        );
    }

    public function test_superadmin_toggles_another_users_access(): void
    {
        $superadmin = User::factory()->superadmin()->create(['telegram_user_id' => 1106]);
        $admin = User::factory()->admin()->create(['telegram_user_id' => 1107, 'name' => 'Пётр', 'is_active' => true]);

        $this->postCallback($superadmin->telegram_user_id, "users:toggle:{$admin->id}");

        $this->assertFalse($admin->fresh()->is_active);
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'Пётр')
            && str_contains($r['text'] ?? '', 'отключён')
        );

        $this->postCallback($superadmin->telegram_user_id, "users:toggle:{$admin->id}");
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_superadmin_cannot_deactivate_self(): void
    {
        $superadmin = User::factory()->superadmin()->create(['telegram_user_id' => 1108, 'is_active' => true]);

        $this->postCallback($superadmin->telegram_user_id, "users:toggle:{$superadmin->id}");

        $this->assertTrue($superadmin->fresh()->is_active);
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'Нельзя деактивировать самого себя'));
    }

    public function test_admin_cannot_trigger_toggle_callback(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 1109]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 1110, 'is_active' => true]);

        $this->postCallback($admin->telegram_user_id, "users:toggle:{$masterUser->id}");

        $this->assertTrue($masterUser->fresh()->is_active);
    }
}
