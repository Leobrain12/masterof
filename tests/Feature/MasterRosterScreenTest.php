<?php

namespace Tests\Feature;

use App\Models\ApplianceType;
use App\Models\Master;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MasterRosterScreenTest extends TestCase
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

    public function test_roster_lists_masters_with_specialization(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 7001]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 8001, 'name' => 'Ахмед']);
        $master = Master::factory()->for($masterUser)->create(['name' => 'Ахмед', 'phone' => '+79161234567']);
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();
        $master->applianceTypes()->attach($fridge->id);

        $this->postMessage($admin->telegram_user_id, 'Мастера');

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($r['text'] ?? '', 'Ахмед')
            && str_contains($r['text'] ?? '', '+79161234567')
            && str_contains($r['text'] ?? '', 'Холодильник')
        );
    }

    public function test_inactive_master_shown_as_disabled(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 7002]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 8002, 'name' => 'Игорь']);
        Master::factory()->for($masterUser)->create(['name' => 'Игорь', 'is_active' => false]);

        $this->postMessage($admin->telegram_user_id, 'Мастера');

        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'Игорь')
            && str_contains($r['text'] ?? '', 'отключён')
        );
    }

    public function test_empty_roster_shows_empty_state(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 7003]);

        $this->postMessage($admin->telegram_user_id, 'Мастера');

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && ($r['text'] ?? '') === 'Мастеров нет.'
        );
    }

    public function test_master_cannot_open_roster(): void
    {
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 8003]);

        $this->postMessage($masterUser->telegram_user_id, 'Мастера');

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $masterUser->telegram_user_id
            && str_contains($r['text'] ?? '', 'следующих фаз')
        );
    }
}
