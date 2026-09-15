<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\ApplianceType;
use App\Models\Master;
use App\Models\Order;
use App\Models\OrderVisit;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\WorkReport;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkReportAndPaymentTest extends TestCase
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

    /**
     * @return array{0: Order, 1: User, 2: Master, 3: User}
     */
    private function makeInProgressOrder(int $adminTelegramId, int $masterTelegramId, ?int $estimatedPrice = null): array
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => $adminTelegramId]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => $masterTelegramId, 'name' => 'Ахмед']);
        $master = Master::factory()->for($masterUser)->create(['name' => 'Ахмед']);

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
            'estimated_price' => $estimatedPrice,
        ]);

        $order->update(['status' => OrderStatus::IN_PROGRESS]);

        OrderVisit::query()->create([
            'order_id' => $order->id,
            'visit_number' => 1,
            'visit_date' => $order->visit_date,
            'time_slot_label' => $order->time_slot_label,
            'master_id' => $master->id,
            'status' => 'SCHEDULED',
            'reason' => 'Диагностика',
        ]);

        return [$order, $admin, $master, $masterUser];
    }

    public function test_full_work_report_flow_completes_order_and_closes_visit(): void
    {
        [$order, $admin, $master, $masterUser] = $this->makeInProgressOrder(11001, 12001, estimatedPrice: 6500);

        $this->postCallback($masterUser->telegram_user_id, "order:complete:{$order->number}");
        $this->postMessage($masterUser->telegram_user_id, 'Заменён компрессор, проверена работа');
        $this->postMessage($masterUser->telegram_user_id, '4500');
        $this->postMessage($masterUser->telegram_user_id, '2000');
        $this->postMessage($masterUser->telegram_user_id, '1200');
        $this->postMessage($masterUser->telegram_user_id, '2500');
        $this->postCallback($masterUser->telegram_user_id, 'work_report:media_done');

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $masterUser->telegram_user_id
            && str_contains($r['text'] ?? '', 'Итого клиенту: 6500 ₽')
            && ! str_contains($r['text'] ?? '', '⚠️')
        );

        $this->postCallback($masterUser->telegram_user_id, 'work_report:confirm');

        $order->refresh();
        $this->assertSame(OrderStatus::COMPLETED, $order->status);
        $this->assertSame(6500, $order->final_price);
        $this->assertSame(2000, $order->parts_sell_price);
        $this->assertSame(1200, $order->parts_cost);
        $this->assertSame(2500, $order->master_payout);
        $this->assertNotNull($order->completed_at);

        $report = WorkReport::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('Заменён компрессор, проверена работа', $report->work_description);
        $this->assertSame(4500, $report->labor_price);
        $this->assertSame($master->id, $report->master_id);
        $this->assertNotNull($report->confirmed_at);

        $visit = OrderVisit::query()->where('order_id', $order->id)->where('visit_number', 1)->firstOrFail();
        $this->assertSame('COMPLETED', $visit->status);
        $this->assertNotNull($visit->completed_at);
        $this->assertSame($visit->id, $report->visit_id);

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($r['text'] ?? '', 'выполнена')
            && str_contains($r['reply_markup'] ?? '', "order:pay_full:{$order->number}")
        );
    }

    public function test_warning_shown_when_final_price_differs_from_agreed(): void
    {
        [$order, $admin, $master, $masterUser] = $this->makeInProgressOrder(11002, 12002, estimatedPrice: 5000);

        $this->postCallback($masterUser->telegram_user_id, "order:complete:{$order->number}");
        $this->postMessage($masterUser->telegram_user_id, 'Оказалось сложнее, потребовалась ещё одна деталь');
        $this->postMessage($masterUser->telegram_user_id, '4500');
        $this->postMessage($masterUser->telegram_user_id, '3000');
        $this->postMessage($masterUser->telegram_user_id, '1800');
        $this->postMessage($masterUser->telegram_user_id, '3000');
        $this->postCallback($masterUser->telegram_user_id, 'work_report:media_done');

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $masterUser->telegram_user_id
            && str_contains($r['text'] ?? '', '⚠️')
            && str_contains($r['text'] ?? '', '5000 ₽')
        );

        $this->postCallback($masterUser->telegram_user_id, 'work_report:confirm');

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($r['text'] ?? '', '⚠️')
        );
    }

    public function test_edit_restarts_the_report_flow(): void
    {
        [$order, , , $masterUser] = $this->makeInProgressOrder(11003, 12003);

        $this->postCallback($masterUser->telegram_user_id, "order:complete:{$order->number}");
        $this->postMessage($masterUser->telegram_user_id, 'Первая версия описания');
        $this->postMessage($masterUser->telegram_user_id, '1000');
        $this->postMessage($masterUser->telegram_user_id, '0');
        $this->postMessage($masterUser->telegram_user_id, '0');
        $this->postMessage($masterUser->telegram_user_id, '500');

        $this->postCallback($masterUser->telegram_user_id, 'work_report:edit');

        $this->assertDatabaseHas('pending_inputs', ['user_id' => $masterUser->id, 'kind' => 'work_report']);
        $pending = \App\Models\PendingInput::query()->where('user_id', $masterUser->id)->firstOrFail();
        $this->assertSame('description', $pending->get('step'));

        $this->postMessage($masterUser->telegram_user_id, 'Исправленное описание');
        $this->postMessage($masterUser->telegram_user_id, '1500');
        $this->postMessage($masterUser->telegram_user_id, '0');
        $this->postMessage($masterUser->telegram_user_id, '0');
        $this->postMessage($masterUser->telegram_user_id, '700');
        $this->postCallback($masterUser->telegram_user_id, 'work_report:confirm');

        $order->refresh();
        $this->assertSame(OrderStatus::COMPLETED, $order->status);
        $report = WorkReport::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('Исправленное описание', $report->work_description);
        $this->assertSame(1500, $report->labor_price);
    }

    public function test_other_master_cannot_complete_someone_elses_order(): void
    {
        [$order] = $this->makeInProgressOrder(11004, 12004);

        $strangerUser = User::factory()->master()->create(['telegram_user_id' => 12005]);
        Master::factory()->for($strangerUser)->create();

        $this->postCallback($strangerUser->telegram_user_id, "order:complete:{$order->number}");

        $this->assertDatabaseMissing('pending_inputs', ['user_id' => $strangerUser->id]);
        $this->assertSame(OrderStatus::IN_PROGRESS, $order->fresh()->status);
    }

    private function makeCompletedOrder(int $adminTelegramId, int $finalPrice = 6500): array
    {
        [$order, $admin, $master, $masterUser] = $this->makeInProgressOrder($adminTelegramId, $adminTelegramId + 1000);
        $order->update(['status' => OrderStatus::COMPLETED, 'final_price' => $finalPrice, 'completed_at' => now()]);

        return [$order, $admin, $master, $masterUser];
    }

    public function test_mark_fully_paid_transitions_to_paid(): void
    {
        [$order, $admin] = $this->makeCompletedOrder(13001, 6500);

        $this->postCallback($admin->telegram_user_id, "order:pay_full:{$order->number}");

        $order->refresh();
        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertSame(6500, $order->amount_paid);
        $this->assertSame(0, $order->amount_due);
    }

    public function test_partial_payment_keeps_order_completed_and_computes_amount_due(): void
    {
        [$order, $admin] = $this->makeCompletedOrder(13002, 6500);

        $this->postCallback($admin->telegram_user_id, "order:pay_partial:{$order->number}");
        $this->postMessage($admin->telegram_user_id, '4000');

        $order->refresh();
        $this->assertSame(OrderStatus::COMPLETED, $order->status);
        $this->assertSame(4000, $order->amount_paid);
        $this->assertSame(2500, $order->amount_due);

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($r['text'] ?? '', 'остаток 2500')
        );
    }

    public function test_mark_unpaid_does_not_change_status(): void
    {
        [$order, $admin] = $this->makeCompletedOrder(13003, 6500);

        $this->postCallback($admin->telegram_user_id, "order:pay_none:{$order->number}");

        $order->refresh();
        $this->assertSame(OrderStatus::COMPLETED, $order->status);
        $this->assertNull($order->amount_paid);
    }

    public function test_master_cannot_mark_payment(): void
    {
        [$order, , , $masterUser] = $this->makeCompletedOrder(13004, 6500);

        $this->postCallback($masterUser->telegram_user_id, "order:pay_full:{$order->number}");

        $this->assertSame(OrderStatus::COMPLETED, $order->fresh()->status);
        $this->assertNull($order->fresh()->amount_paid);
    }

    public function test_payment_unavailable_before_completion(): void
    {
        [$order, $admin] = $this->makeInProgressOrder(13005, 13006);

        $this->postCallback($admin->telegram_user_id, "order:pay_full:{$order->number}");

        $this->assertSame(OrderStatus::IN_PROGRESS, $order->fresh()->status);
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'недоступна'));
    }
}
