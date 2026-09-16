<?php

namespace Tests\Feature;

use App\Models\ApplianceType;
use App\Models\Master;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MasterRegistrationFlowTest extends TestCase
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

    public function test_full_wizard_creates_master_with_specialization(): void
    {
        $superadmin = User::factory()->superadmin()->create(['telegram_user_id' => 2201]);
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();

        $this->postCallback($superadmin->telegram_user_id, 'users:add_master');
        $this->assertDatabaseHas('pending_inputs', ['user_id' => $superadmin->id, 'kind' => 'master_registration']);

        $this->postMessage($superadmin->telegram_user_id, '990555001');
        $this->postMessage($superadmin->telegram_user_id, 'Тестовый Мастер');
        $this->postMessage($superadmin->telegram_user_id, '+7 916 555-00-01');

        Http::assertSent(fn ($r) => str_contains($r['reply_markup'] ?? '', "master_reg:appliance:{$fridge->id}")
            && str_contains($r['reply_markup'] ?? '', $fridge->name)
        );

        $this->postCallback($superadmin->telegram_user_id, "master_reg:appliance:{$fridge->id}");
        $this->postCallback($superadmin->telegram_user_id, 'master_reg:appliance:done');
        $this->postCallback($superadmin->telegram_user_id, 'master_reg:brand:skip');
        $this->postCallback($superadmin->telegram_user_id, 'master_reg:zone:skip');

        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'Тестовый Мастер')
            && str_contains($r['text'] ?? '', '+79165550001')
            && str_contains($r['text'] ?? '', '990555001')
            && str_contains($r['text'] ?? '', $fridge->name)
        );

        $this->postCallback($superadmin->telegram_user_id, 'master_reg:confirm:create');

        $this->assertDatabaseCount('pending_inputs', 0);
        $user = User::query()->where('telegram_user_id', 990555001)->firstOrFail();
        $this->assertTrue($user->isMaster());
        $this->assertSame('Тестовый Мастер', $user->name);

        $master = Master::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertTrue($master->applianceTypes->contains('id', $fridge->id));

        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'Тестовый Мастер') && str_contains($r['text'] ?? '', 'добавлен'));
    }

    public function test_rejects_non_numeric_telegram_id_and_retries(): void
    {
        $superadmin = User::factory()->superadmin()->create(['telegram_user_id' => 2202]);

        $this->postCallback($superadmin->telegram_user_id, 'users:add_master');
        $this->postMessage($superadmin->telegram_user_id, 'не число');

        $this->assertDatabaseHas('pending_inputs', ['user_id' => $superadmin->id, 'kind' => 'master_registration']);
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'целое число'));
    }

    public function test_rejects_duplicate_telegram_id(): void
    {
        $superadmin = User::factory()->superadmin()->create(['telegram_user_id' => 2203]);
        User::factory()->master()->create(['telegram_user_id' => 990555002]);

        $this->postCallback($superadmin->telegram_user_id, 'users:add_master');
        $this->postMessage($superadmin->telegram_user_id, '990555002');

        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'уже существует'));
        $this->assertDatabaseHas('pending_inputs', ['user_id' => $superadmin->id, 'kind' => 'master_registration']);
    }

    public function test_rejects_invalid_phone(): void
    {
        $superadmin = User::factory()->superadmin()->create(['telegram_user_id' => 2204]);

        $this->postCallback($superadmin->telegram_user_id, 'users:add_master');
        $this->postMessage($superadmin->telegram_user_id, '990555003');
        $this->postMessage($superadmin->telegram_user_id, 'Мастер');
        $this->postMessage($superadmin->telegram_user_id, 'абв');

        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'не телефон'));
    }

    public function test_appliance_done_with_none_selected_is_rejected(): void
    {
        $superadmin = User::factory()->superadmin()->create(['telegram_user_id' => 2205]);

        $this->postCallback($superadmin->telegram_user_id, 'users:add_master');
        $this->postMessage($superadmin->telegram_user_id, '990555004');
        $this->postMessage($superadmin->telegram_user_id, 'Мастер');
        $this->postMessage($superadmin->telegram_user_id, '+79995550004');

        $this->postCallback($superadmin->telegram_user_id, 'master_reg:appliance:done');

        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'хотя бы одна специализация'));
        $this->assertDatabaseHas('pending_inputs', ['user_id' => $superadmin->id, 'kind' => 'master_registration']);
        $this->assertDatabaseMissing('users', ['telegram_user_id' => 990555004]);
    }

    public function test_cancel_at_confirmation_creates_nothing(): void
    {
        $superadmin = User::factory()->superadmin()->create(['telegram_user_id' => 2206]);
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();

        $this->postCallback($superadmin->telegram_user_id, 'users:add_master');
        $this->postMessage($superadmin->telegram_user_id, '990555005');
        $this->postMessage($superadmin->telegram_user_id, 'Мастер');
        $this->postMessage($superadmin->telegram_user_id, '+79995550005');
        $this->postCallback($superadmin->telegram_user_id, "master_reg:appliance:{$fridge->id}");
        $this->postCallback($superadmin->telegram_user_id, 'master_reg:appliance:done');
        $this->postCallback($superadmin->telegram_user_id, 'master_reg:brand:skip');
        $this->postCallback($superadmin->telegram_user_id, 'master_reg:zone:skip');

        $this->postCallback($superadmin->telegram_user_id, 'master_reg:confirm:cancel');

        $this->assertDatabaseCount('pending_inputs', 0);
        $this->assertDatabaseMissing('users', ['telegram_user_id' => 990555005]);
        Http::assertSent(fn ($r) => ($r['text'] ?? '') === 'Отменено.');
    }

    public function test_admin_cannot_trigger_master_reg_namespace(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 2207]);

        $this->postCallback($admin->telegram_user_id, 'users:add_master');
        $this->assertDatabaseCount('pending_inputs', 0);

        $this->postCallback($admin->telegram_user_id, 'master_reg:confirm:create');
        $this->assertDatabaseCount('users', 1);
    }
}
