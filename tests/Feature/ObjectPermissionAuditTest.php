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

/**
 * ТЗ п.92: "MASTER A не должен иметь возможность открыть Order MASTER B даже
 * через подстановку UUID" — здесь через номер заказа в callback_data, что и
 * есть реальная угроза (номер угадать проще, чем UUID; см. vault/Фазы/Фаза 07).
 *
 * По одному тесту на каждый flow, работающий через MasterOrderGuard, а не
 * только на самые очевидные (accept/decline) — систематический прогон, а не
 * выборочный. Общий инвариант для всех: заказ не меняет статус/данные,
 * "чужой" мастер получает отказ, владелец заказа не задет.
 */
class ObjectPermissionAuditTest extends TestCase
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

    /**
     * @return array{0: Order, 1: User}  Заказ и "чужой" мастер, не имеющий к нему отношения
     */
    private function makeOrderWithStranger(OrderStatus $status): array
    {
        $admin = User::factory()->admin()->create();
        $ownerUser = User::factory()->master()->create();
        $owner = Master::factory()->for($ownerUser)->create();
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();
        $slot = TimeSlot::first();

        $order = Order::create([
            'customer_name' => 'Клиент', 'customer_phone' => '+79990001122',
            'appliance_type_id' => $fridge->id, 'symptom' => 'Не морозит', 'address' => 'Москва',
            'visit_date' => now()->toDateString(), 'time_slot_label' => $slot->label, 'time_slot_id' => $slot->id,
            'status' => OrderStatus::NEW, 'master_id' => $owner->id, 'created_by' => $admin->id,
        ]);
        $order->update(['status' => $status]);

        $strangerUser = User::factory()->master()->create();
        Master::factory()->for($strangerUser)->create();

        return [$order, $strangerUser];
    }

    public function test_diagnosis_flow_denies_stranger(): void
    {
        [$order, $stranger] = $this->makeOrderWithStranger(OrderStatus::ARRIVED);

        $this->postCallback($stranger->telegram_user_id, "order:diagnose:{$order->number}");

        $this->assertSame(OrderStatus::ARRIVED, $order->fresh()->status);
    }

    public function test_diagnosis_resolve_denies_stranger(): void
    {
        [$order, $stranger] = $this->makeOrderWithStranger(OrderStatus::DIAGNOSTICS);

        $this->postCallback($stranger->telegram_user_id, "order:diagnosis:{$order->number}:REPAIRABLE");

        $this->assertSame(OrderStatus::DIAGNOSTICS, $order->fresh()->status);
    }

    public function test_price_approval_start_denies_stranger(): void
    {
        [$order, $stranger] = $this->makeOrderWithStranger(OrderStatus::PRICE_APPROVAL);

        $this->postCallback($stranger->telegram_user_id, "order:price_start:{$order->number}");

        $this->assertDatabaseMissing('pending_inputs', ['user_id' => $stranger->id]);
    }

    public function test_price_approval_approve_denies_stranger(): void
    {
        [$order, $stranger] = $this->makeOrderWithStranger(OrderStatus::PRICE_APPROVAL);
        $order->update(['labor_price' => 1000, 'parts_sell_price' => 0, 'estimated_price' => 1000]);

        $this->postCallback($stranger->telegram_user_id, "order:price_approve:{$order->number}");

        $this->assertSame(OrderStatus::PRICE_APPROVAL, $order->fresh()->status);
    }

    public function test_contact_attempt_flow_denies_stranger(): void
    {
        [$order, $stranger] = $this->makeOrderWithStranger(OrderStatus::ACCEPTED);

        $this->postCallback($stranger->telegram_user_id, "order:no_contact:{$order->number}");
        $this->postCallback($stranger->telegram_user_id, "order:contact_result:{$order->number}:NO_ANSWER");

        $this->assertSame(0, \App\Models\ContactAttempt::query()->where('order_id', $order->id)->count());
    }

    public function test_reschedule_flow_denies_stranger(): void
    {
        [$order, $stranger] = $this->makeOrderWithStranger(OrderStatus::ACCEPTED);
        $originalDate = $order->visit_date->toDateString();

        $this->postCallback($stranger->telegram_user_id, "order:reschedule:{$order->number}");
        $this->postCallback($stranger->telegram_user_id, 'resched:date:tomorrow');

        $this->assertDatabaseMissing('pending_inputs', ['user_id' => $stranger->id]);
        $this->assertSame($originalDate, $order->fresh()->visit_date->toDateString());
    }

    public function test_part_request_flow_denies_stranger(): void
    {
        [$order, $stranger] = $this->makeOrderWithStranger(OrderStatus::DIAGNOSTICS);

        $this->postCallback($stranger->telegram_user_id, "order:diagnosis:{$order->number}:NEED_PART");

        $this->assertDatabaseMissing('pending_inputs', ['user_id' => $stranger->id]);
        $this->assertSame(OrderStatus::DIAGNOSTICS, $order->fresh()->status);
    }

    public function test_media_collection_flow_denies_stranger(): void
    {
        [$order, $stranger] = $this->makeOrderWithStranger(OrderStatus::IN_PROGRESS);

        $this->postCallback($stranger->telegram_user_id, "order:add_media:{$order->number}");

        $this->assertDatabaseMissing('pending_inputs', ['user_id' => $stranger->id]);
    }

    public function test_work_report_flow_denies_stranger(): void
    {
        [$order, $stranger] = $this->makeOrderWithStranger(OrderStatus::IN_PROGRESS);

        $this->postCallback($stranger->telegram_user_id, "order:complete:{$order->number}");

        $this->assertDatabaseMissing('pending_inputs', ['user_id' => $stranger->id]);
        $this->assertSame(OrderStatus::IN_PROGRESS, $order->fresh()->status);
    }

    public function test_order_visit_flow_denies_stranger(): void
    {
        [$order, $stranger] = $this->makeOrderWithStranger(OrderStatus::WAITING_PART);

        $this->postCallback($stranger->telegram_user_id, "order:visit_next:{$order->number}");

        $this->assertDatabaseMissing('pending_inputs', ['user_id' => $stranger->id]);
        $this->assertSame(OrderStatus::WAITING_PART, $order->fresh()->status);
    }
}
