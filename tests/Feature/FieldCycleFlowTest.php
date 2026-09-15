<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\ApplianceType;
use App\Models\ContactAttempt;
use App\Models\Master;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\TimeSlot;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FieldCycleFlowTest extends TestCase
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

    private function makeAcceptedOrder(User $admin, Master $master): Order
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

        $order->update(['status' => OrderStatus::ACCEPTED]);

        return $order;
    }

    public function test_full_field_cycle_happy_path(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 7001]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 8001, 'name' => 'Ахмед']);
        $master = Master::factory()->for($masterUser)->create(['name' => 'Ахмед']);
        $order = $this->makeAcceptedOrder($admin, $master);

        $this->postCallback($masterUser->telegram_user_id, "order:depart:{$order->number}");
        $this->assertSame(OrderStatus::ON_THE_WAY, $order->fresh()->status);

        $this->postCallback($masterUser->telegram_user_id, "order:arrive:{$order->number}");
        $this->assertSame(OrderStatus::ARRIVED, $order->fresh()->status);

        $this->postCallback($masterUser->telegram_user_id, "order:diagnose:{$order->number}");
        $this->assertSame(OrderStatus::DIAGNOSTICS, $order->fresh()->status);

        $this->postCallback($masterUser->telegram_user_id, "order:diagnosis:{$order->number}:REPAIRABLE");
        $this->assertSame(OrderStatus::PRICE_APPROVAL, $order->fresh()->status);

        $this->postCallback($masterUser->telegram_user_id, "order:price_start:{$order->number}");
        $this->postMessage($masterUser->telegram_user_id, 'Неисправен компрессор');
        $this->postMessage($masterUser->telegram_user_id, 'Замена компрессора');
        $this->postMessage($masterUser->telegram_user_id, '5000');
        $this->postMessage($masterUser->telegram_user_id, '4000');

        $order->refresh();
        $this->assertSame('Неисправен компрессор', $order->failure_reason);
        $this->assertSame('Замена компрессора', $order->work_needed);
        $this->assertSame(5000, $order->labor_price);
        $this->assertSame(4000, $order->parts_sell_price);
        $this->assertSame(9000, $order->estimated_price);

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $masterUser->telegram_user_id
            && str_contains($r['text'] ?? '', 'Итого: 9000 ₽')
        );

        $this->postCallback($masterUser->telegram_user_id, "order:price_approve:{$order->number}");

        $this->assertSame(OrderStatus::IN_PROGRESS, $order->fresh()->status);

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($r['text'] ?? '', 'приступил к работе')
        );

        $statusChain = OrderStatusHistory::query()
            ->where('order_id', $order->id)
            ->orderBy('created_at')
            ->pluck('new_status')
            ->map(fn ($status) => $status->value)
            ->all();

        // makeAcceptedOrder выставляет ACCEPTED напрямую, в обход OrderStatusMachine —
        // истории на саму эту заявку ещё не было, цепочка начинается с первого реального перехода.
        $this->assertSame(
            ['ON_THE_WAY', 'ARRIVED', 'DIAGNOSTICS', 'PRICE_APPROVAL', 'IN_PROGRESS'],
            $statusChain
        );
    }

    public function test_diagnosis_need_part_starts_part_request_flow_instead_of_transitioning_immediately(): void
    {
        // Полный сценарий "деталь → визит #2" — в Tests\Feature\PartAndVisitFlowTest (фаза 03).
        $admin = User::factory()->admin()->create(['telegram_user_id' => 7002]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 8002]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeAcceptedOrder($admin, $master);
        $order->update(['status' => OrderStatus::DIAGNOSTICS]);

        $this->postCallback($masterUser->telegram_user_id, "order:diagnosis:{$order->number}:NEED_PART");

        $this->assertSame(OrderStatus::DIAGNOSTICS, $order->fresh()->status);
        $this->assertDatabaseHas('pending_inputs', ['user_id' => $masterUser->id, 'kind' => 'waiting_part']);
        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $masterUser->telegram_user_id
            && str_contains($r['text'] ?? '', 'Название детали')
        );
    }

    public function test_diagnosis_customer_declined_sends_cancellation_notice_once(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 7003]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 8003]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeAcceptedOrder($admin, $master);
        $order->update(['status' => OrderStatus::DIAGNOSTICS]);

        $this->postCallback($masterUser->telegram_user_id, "order:diagnosis:{$order->number}:CUSTOMER_DECLINED");

        $this->assertSame(OrderStatus::CUSTOMER_CANCELLED, $order->fresh()->status);

        $adminMessages = Http::recorded(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($r['text'] ?? '', $order->code())
        );
        $this->assertCount(1, $adminMessages);
    }

    public function test_price_decline_with_predefined_reason(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 7004]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 8004]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeAcceptedOrder($admin, $master);
        $order->update(['status' => OrderStatus::PRICE_APPROVAL, 'labor_price' => 4500, 'parts_sell_price' => 2000, 'estimated_price' => 6500]);

        $this->postCallback($masterUser->telegram_user_id, "order:price_decline:{$order->number}");
        $this->postCallback($masterUser->telegram_user_id, "order:price_decline_reason:{$order->number}:EXPENSIVE");

        $order->refresh();
        $this->assertSame(OrderStatus::CUSTOMER_CANCELLED, $order->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'new_status' => 'CUSTOMER_CANCELLED',
            'comment' => 'Дорого',
        ]);
    }

    public function test_price_decline_with_custom_reason_via_pending_input(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 7005]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 8005]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeAcceptedOrder($admin, $master);
        $order->update(['status' => OrderStatus::PRICE_APPROVAL, 'labor_price' => 4500, 'parts_sell_price' => 2000, 'estimated_price' => 6500]);

        $this->postCallback($masterUser->telegram_user_id, "order:price_decline:{$order->number}");
        $this->postCallback($masterUser->telegram_user_id, "order:price_decline_reason:{$order->number}:OTHER");
        $this->postMessage($masterUser->telegram_user_id, 'Нашёл другую компанию дешевле');

        $order->refresh();
        $this->assertSame(OrderStatus::CUSTOMER_CANCELLED, $order->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'new_status' => 'CUSTOMER_CANCELLED',
            'comment' => 'Другое: Нашёл другую компанию дешевле',
        ]);
    }

    public function test_cannot_approve_price_before_entering_it(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 7006]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 8006]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeAcceptedOrder($admin, $master);
        $order->update(['status' => OrderStatus::PRICE_APPROVAL]);

        $this->postCallback($masterUser->telegram_user_id, "order:price_approve:{$order->number}");

        $this->assertSame(OrderStatus::PRICE_APPROVAL, $order->fresh()->status);
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'Сначала укажи стоимость'));
    }

    public function test_no_contact_attempts_accumulate_and_escalation_offered_after_threshold(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 7007]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 8007]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeAcceptedOrder($admin, $master);

        foreach (range(1, 3) as $i) {
            $this->postCallback($masterUser->telegram_user_id, "order:no_contact:{$order->number}");
            $this->postCallback($masterUser->telegram_user_id, "order:contact_result:{$order->number}:NO_ANSWER");
        }

        $this->assertSame(3, ContactAttempt::query()->where('order_id', $order->id)->count());
        $this->assertSame(OrderStatus::ACCEPTED, $order->fresh()->status);

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($r['reply_markup'] ?? '', "order:no_contact_escalate:{$order->number}")
        );

        $this->postCallback($admin->telegram_user_id, "order:no_contact_escalate:{$order->number}");

        $this->assertSame(OrderStatus::NO_CONTACT, $order->fresh()->status);
    }

    public function test_master_cannot_escalate_no_contact(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 7008]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 8008]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeAcceptedOrder($admin, $master);

        $this->postCallback($masterUser->telegram_user_id, "order:no_contact_escalate:{$order->number}");

        $this->assertSame(OrderStatus::ACCEPTED, $order->fresh()->status);
    }

    public function test_reschedule_updates_date_and_slot_without_changing_status(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 7009]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 8009]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeAcceptedOrder($admin, $master);
        $newSlot = TimeSlot::query()->orderBy('sort_order', 'desc')->first();

        $this->postCallback($masterUser->telegram_user_id, "order:reschedule:{$order->number}");
        $this->postCallback($masterUser->telegram_user_id, 'resched:date:tomorrow');
        $this->postCallback($masterUser->telegram_user_id, "resched:slot:{$newSlot->id}");
        $this->postCallback($masterUser->telegram_user_id, 'resched:reason:NO_PART');

        $order->refresh();
        $this->assertSame(OrderStatus::ACCEPTED, $order->status);
        $this->assertSame(now()->addDay()->toDateString(), $order->visit_date->toDateString());
        $this->assertSame($newSlot->label, $order->time_slot_label);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'old_status' => 'ACCEPTED',
            'new_status' => 'ACCEPTED',
        ]);

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($r['text'] ?? '', 'перенесена')
            && str_contains($r['text'] ?? '', 'Нет детали')
        );
    }

    public function test_reschedule_with_custom_date_and_reason(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 7010]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 8010]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeAcceptedOrder($admin, $master);

        $this->postCallback($masterUser->telegram_user_id, "order:reschedule:{$order->number}");
        $this->postCallback($masterUser->telegram_user_id, 'resched:date:custom');
        $futureDate = now()->addDays(10);
        $this->postMessage($masterUser->telegram_user_id, $futureDate->format('d.m'));
        $this->postCallback($masterUser->telegram_user_id, 'resched:slot:custom');
        $this->postMessage($masterUser->telegram_user_id, '19:00–20:00');
        $this->postCallback($masterUser->telegram_user_id, 'resched:reason:OTHER');
        $this->postMessage($masterUser->telegram_user_id, 'Клиент попросил перенести на следующую неделю');

        $order->refresh();
        $this->assertSame($futureDate->toDateString(), $order->visit_date->toDateString());
        $this->assertSame('19:00–20:00', $order->time_slot_label);

        $comment = OrderStatusHistory::query()->where('order_id', $order->id)->latest('created_at')->value('comment');
        $this->assertStringContainsString('19:00–20:00', $comment);
        $this->assertStringContainsString('Другое: Клиент попросил перенести на следующую неделю', $comment);
    }

    public function test_other_master_cannot_advance_someone_elses_order(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 7011]);
        $ownerUser = User::factory()->master()->create(['telegram_user_id' => 8011]);
        $owner = Master::factory()->for($ownerUser)->create();
        $order = $this->makeAcceptedOrder($admin, $owner);

        $strangerUser = User::factory()->master()->create(['telegram_user_id' => 8012]);
        Master::factory()->for($strangerUser)->create();

        $this->postCallback($strangerUser->telegram_user_id, "order:depart:{$order->number}");

        $this->assertSame(OrderStatus::ACCEPTED, $order->fresh()->status);
    }
}
