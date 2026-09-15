<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\ApplianceType;
use App\Models\Master;
use App\Models\Order;
use App\Models\OrderVisit;
use App\Models\TimeSlot;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PartAndVisitFlowTest extends TestCase
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

    private function makeDiagnosticsOrder(User $admin, Master $master): Order
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

        $order->update(['status' => OrderStatus::DIAGNOSTICS]);

        OrderVisit::query()->create([
            'order_id' => $order->id,
            'visit_number' => 1,
            'visit_date' => $order->visit_date,
            'time_slot_label' => $order->time_slot_label,
            'master_id' => $master->id,
            'status' => 'SCHEDULED',
            'reason' => 'Диагностика',
        ]);

        return $order;
    }

    public function test_full_part_request_collects_all_fields_and_transitions(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 9001]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 9101, 'name' => 'Ахмед']);
        $master = Master::factory()->for($masterUser)->create(['name' => 'Ахмед']);
        $order = $this->makeDiagnosticsOrder($admin, $master);

        $this->postCallback($masterUser->telegram_user_id, "order:diagnosis:{$order->number}:NEED_PART");
        $this->postMessage($masterUser->telegram_user_id, 'Компрессор Danfoss NL9F');
        $this->postMessage($masterUser->telegram_user_id, 'DNF-NL9F-2024');
        $this->postMessage($masterUser->telegram_user_id, '8500');
        $this->postMessage($masterUser->telegram_user_id, 'Нужно заказать у поставщика, срок 3-5 дней');
        $this->postCallback($masterUser->telegram_user_id, 'part:media_done');

        $order->refresh();
        $this->assertSame(OrderStatus::WAITING_PART, $order->status);
        $this->assertSame('Компрессор Danfoss NL9F', $order->part_name);
        $this->assertSame('DNF-NL9F-2024', $order->part_article);
        $this->assertSame(8500, $order->part_purchase_price);
        $this->assertSame('Нужно заказать у поставщика, срок 3-5 дней', $order->part_comment);

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($r['text'] ?? '', 'требуется запчасть')
            && str_contains($r['text'] ?? '', 'Компрессор Danfoss NL9F')
            && str_contains($r['text'] ?? '', 'DNF-NL9F-2024')
            && str_contains($r['text'] ?? '', '8500')
        );
    }

    public function test_part_request_optional_fields_can_be_skipped(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 9002]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 9102]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeDiagnosticsOrder($admin, $master);

        $this->postCallback($masterUser->telegram_user_id, "order:diagnosis:{$order->number}:NEED_PART");
        $this->postMessage($masterUser->telegram_user_id, 'Ремень барабана');
        $this->postCallback($masterUser->telegram_user_id, 'part:skip');
        $this->postCallback($masterUser->telegram_user_id, 'part:skip');
        $this->postCallback($masterUser->telegram_user_id, 'part:skip');
        $this->postCallback($masterUser->telegram_user_id, 'part:media_done');

        $order->refresh();
        $this->assertSame(OrderStatus::WAITING_PART, $order->status);
        $this->assertSame('Ремень барабана', $order->part_name);
        $this->assertNull($order->part_article);
        $this->assertNull($order->part_purchase_price);
        $this->assertNull($order->part_comment);
    }

    public function test_scheduling_next_visit_creates_visit_two_and_returns_order_to_accepted(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 9003]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 9103, 'name' => 'Ахмед']);
        $master = Master::factory()->for($masterUser)->create(['name' => 'Ахмед']);
        $order = $this->makeDiagnosticsOrder($admin, $master);
        $order->update([
            'status' => OrderStatus::WAITING_PART,
            'part_name' => 'Компрессор',
        ]);

        $newSlot = TimeSlot::query()->orderBy('sort_order', 'desc')->first();

        $this->postCallback($masterUser->telegram_user_id, "order:visit_next:{$order->number}");
        $this->postCallback($masterUser->telegram_user_id, 'visit:date:tomorrow');
        $this->postCallback($masterUser->telegram_user_id, "visit:slot:{$newSlot->id}");

        $order->refresh();
        $this->assertSame(OrderStatus::ACCEPTED, $order->status);
        $this->assertSame(now()->addDay()->toDateString(), $order->visit_date->toDateString());
        $this->assertSame($newSlot->label, $order->time_slot_label);

        $this->assertSame(2, OrderVisit::query()->where('order_id', $order->id)->count());

        $visit1 = OrderVisit::query()->where('order_id', $order->id)->where('visit_number', 1)->firstOrFail();
        $this->assertSame('COMPLETED', $visit1->status);
        $this->assertNotNull($visit1->completed_at);

        $visit2 = OrderVisit::query()->where('order_id', $order->id)->where('visit_number', 2)->firstOrFail();
        $this->assertSame('SCHEDULED', $visit2->status);
        $this->assertSame($newSlot->label, $visit2->time_slot_label);
        $this->assertSame($master->id, $visit2->master_id);

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($r['text'] ?? '', 'визит #2')
        );
    }

    public function test_second_visit_goes_through_field_cycle_again(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 9004]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 9104]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeDiagnosticsOrder($admin, $master);
        $order->update(['status' => OrderStatus::WAITING_PART, 'part_name' => 'Деталь']);

        $this->postCallback($masterUser->telegram_user_id, "order:visit_next:{$order->number}");
        $this->postCallback($masterUser->telegram_user_id, 'visit:date:today');
        $this->postCallback($masterUser->telegram_user_id, 'visit:slot:custom');
        $this->postMessage($masterUser->telegram_user_id, '10:00–11:00');

        $this->assertSame(OrderStatus::ACCEPTED, $order->fresh()->status);

        $this->postCallback($masterUser->telegram_user_id, "order:depart:{$order->number}");
        $this->assertSame(OrderStatus::ON_THE_WAY, $order->fresh()->status);
    }

    public function test_visit_one_is_created_when_order_is_created(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 9005]);
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 9105]);
        $master = Master::factory()->for($masterUser)->create();
        $master->applianceTypes()->attach($fridge->id);

        $this->postMessage($admin->telegram_user_id, 'Новая заявка');
        $this->postCallback($admin->telegram_user_id, "draft:appliance_type:{$fridge->id}");
        $this->postCallback($admin->telegram_user_id, 'draft:brand:unknown');
        $this->postCallback($admin->telegram_user_id, 'draft:model:skip');
        $symptom = \App\Models\Symptom::where('appliance_type_id', $fridge->id)->firstOrFail();
        $this->postCallback($admin->telegram_user_id, "draft:symptom:{$symptom->id}");
        $this->postCallback($admin->telegram_user_id, 'draft:description:skip');
        $this->postMessage($admin->telegram_user_id, 'Клиент');
        $this->postMessage($admin->telegram_user_id, '+79990001122');
        $this->postMessage($admin->telegram_user_id, 'Москва');
        $this->postCallback($admin->telegram_user_id, 'draft:zone:skip');
        $this->postCallback($admin->telegram_user_id, 'draft:date:today');
        $this->postCallback($admin->telegram_user_id, 'draft:slot:custom');
        $this->postMessage($admin->telegram_user_id, '19:00–20:00');
        $this->postCallback($admin->telegram_user_id, "draft:master:{$master->id}");
        $this->postCallback($admin->telegram_user_id, 'draft:confirm:create');

        $order = Order::query()->firstOrFail();
        $this->assertSame(1, OrderVisit::query()->where('order_id', $order->id)->count());
        $visit = OrderVisit::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(1, $visit->visit_number);
        $this->assertSame('SCHEDULED', $visit->status);
    }

    public function test_other_master_cannot_schedule_visit_for_someone_elses_order(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 9006]);
        $ownerUser = User::factory()->master()->create(['telegram_user_id' => 9106]);
        $owner = Master::factory()->for($ownerUser)->create();
        $order = $this->makeDiagnosticsOrder($admin, $owner);
        $order->update(['status' => OrderStatus::WAITING_PART, 'part_name' => 'Деталь']);

        $strangerUser = User::factory()->master()->create(['telegram_user_id' => 9107]);
        Master::factory()->for($strangerUser)->create();

        $this->postCallback($strangerUser->telegram_user_id, "order:visit_next:{$order->number}");

        $this->assertDatabaseMissing('pending_inputs', ['user_id' => $strangerUser->id]);
        $this->assertSame(OrderStatus::WAITING_PART, $order->fresh()->status);
    }
}
