<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\ApplianceType;
use App\Models\Master;
use App\Models\Order;
use App\Models\TimeSlot;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderDecisionFlowTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_URL = '/api/telegram/webhook';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Http::fake();
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

    private function makeAssignedOrder(User $admin, Master $master): Order
    {
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();
        $slot = TimeSlot::first();

        $order = Order::create([
            'customer_name' => 'Клиент',
            'customer_phone' => '+79990001122',
            'appliance_type_id' => $fridge->id,
            'symptom' => 'Не морозит',
            'address' => 'Москва',
            'visit_date' => now()->toDateString(),
            'time_slot_label' => $slot->label,
            'time_slot_id' => $slot->id,
            'status' => OrderStatus::NEW,
            'master_id' => $master->id,
            'created_by' => $admin->id,
        ]);

        $order->update(['status' => OrderStatus::ASSIGNED]);

        return $order;
    }

    public function test_master_accepts_order(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 3001]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 4001, 'name' => 'Иван']);
        $master = Master::factory()->for($masterUser)->create(['name' => 'Иван']);
        $order = $this->makeAssignedOrder($admin, $master);

        $this->postCallback($masterUser->telegram_user_id, "order:accept:{$order->number}");

        $this->assertSame(OrderStatus::ACCEPTED, $order->fresh()->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'old_status' => 'ASSIGNED',
            'new_status' => 'ACCEPTED',
            'changed_by_user_id' => $masterUser->id,
        ]);

        Http::assertSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($request['text'] ?? '', 'принял заявку')
        );
    }

    public function test_other_master_cannot_accept_someone_elses_order(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 3002]);
        $ownerUser = User::factory()->master()->create(['telegram_user_id' => 4002]);
        $owner = Master::factory()->for($ownerUser)->create();
        $order = $this->makeAssignedOrder($admin, $owner);

        $strangerUser = User::factory()->master()->create(['telegram_user_id' => 4003]);
        Master::factory()->for($strangerUser)->create();

        $this->postCallback($strangerUser->telegram_user_id, "order:accept:{$order->number}");

        $this->assertSame(OrderStatus::ASSIGNED, $order->fresh()->status);
        Http::assertSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === $strangerUser->telegram_user_id
            && str_contains($request['text'] ?? '', 'назначена не тебе')
        );
    }

    public function test_double_accept_is_handled_gracefully(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 3003]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 4004]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeAssignedOrder($admin, $master);

        $this->postCallback($masterUser->telegram_user_id, "order:accept:{$order->number}");
        $this->postCallback($masterUser->telegram_user_id, "order:accept:{$order->number}");

        $this->assertSame(1, \App\Models\OrderStatusHistory::query()->where('new_status', 'ACCEPTED')->count());
        Http::assertSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === $masterUser->telegram_user_id
            && str_contains($request['text'] ?? '', 'уже изменена')
        );
    }

    public function test_master_declines_with_predefined_reason_and_admin_can_reassign(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 3004]);
        $decliningUser = User::factory()->master()->create(['telegram_user_id' => 4005, 'name' => 'Иван']);
        $decliningMaster = Master::factory()->for($decliningUser)->create(['name' => 'Иван']);
        $order = $this->makeAssignedOrder($admin, $decliningMaster);

        $this->postCallback($decliningUser->telegram_user_id, "order:decline:{$order->number}");
        $this->postCallback($decliningUser->telegram_user_id, "order:decline_reason:{$order->number}:TOO_FAR");

        $order->refresh();
        $this->assertSame(OrderStatus::MASTER_DECLINED, $order->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'new_status' => 'MASTER_DECLINED',
            'comment' => 'Слишком далеко',
        ]);

        $newUser = User::factory()->master()->create(['telegram_user_id' => 4006, 'name' => 'Новый']);
        $newMaster = Master::factory()->for($newUser)->create(['name' => 'Новый']);

        $this->postCallback($admin->telegram_user_id, "order:reassign:{$order->number}");
        $this->postCallback($admin->telegram_user_id, "reassign:master:{$order->number}:{$newMaster->id}");

        $order->refresh();
        $this->assertSame(OrderStatus::ASSIGNED, $order->status);
        $this->assertSame($newMaster->id, $order->master_id);

        Http::assertSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === $newUser->telegram_user_id
            && str_contains($request['reply_markup'] ?? '', "order:accept:{$order->number}")
        );
    }

    public function test_master_declines_with_custom_reason_via_pending_input(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 3005]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 4007]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeAssignedOrder($admin, $master);

        $this->postCallback($masterUser->telegram_user_id, "order:decline:{$order->number}");
        $this->postCallback($masterUser->telegram_user_id, "order:decline_reason:{$order->number}:OTHER");

        $this->assertDatabaseHas('pending_inputs', ['user_id' => $masterUser->id, 'kind' => 'order_decline_reason']);

        $this->postMessage($masterUser->telegram_user_id, 'Заболел, не смогу приехать');

        $order->refresh();
        $this->assertSame(OrderStatus::MASTER_DECLINED, $order->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'new_status' => 'MASTER_DECLINED',
            'comment' => 'Другая причина: Заболел, не смогу приехать',
        ]);
        $this->assertDatabaseCount('pending_inputs', 0);
    }

    public function test_master_cannot_trigger_reassign(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 3006]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 4008]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeAssignedOrder($admin, $master);
        $order->update(['status' => OrderStatus::MASTER_DECLINED]);

        $this->postCallback($masterUser->telegram_user_id, "order:reassign:{$order->number}");

        Http::assertNotSent(fn ($request) => str_contains($request['text'] ?? '', 'Подходящие мастера'));
    }
}
