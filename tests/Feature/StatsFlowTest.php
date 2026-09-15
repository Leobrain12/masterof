<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\ApplianceType;
use App\Models\Master;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Orders\MasterStatsCalculator;
use App\Services\Orders\OrderStatsCalculator;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StatsFlowTest extends TestCase
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

    private function makeOrder(User $admin, Master $master, OrderStatus $status, array $overrides = []): Order
    {
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();
        $slot = TimeSlot::first();

        $order = Order::create(array_merge([
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
        ], $overrides));

        $order->update(['status' => $status]);

        return $order;
    }

    public function test_master_stats_calculates_completed_revenue_and_rates(): void
    {
        $admin = User::factory()->admin()->create();
        $masterUser = User::factory()->master()->create();
        $master = Master::factory()->for($masterUser)->create();

        $now = now();

        // Назначен и принят в периоде, выполнен и оплачен в периоде.
        $completed = $this->makeOrder($admin, $master, OrderStatus::PAID, [
            'final_price' => 6000, 'parts_cost' => 1000, 'master_payout' => 2500, 'completed_at' => $now,
        ]);
        OrderStatusHistory::create(['order_id' => $completed->id, 'old_status' => 'NEW', 'new_status' => 'ASSIGNED', 'created_at' => $now]);
        OrderStatusHistory::create(['order_id' => $completed->id, 'old_status' => 'ASSIGNED', 'new_status' => 'ACCEPTED', 'changed_by_user_id' => $masterUser->id, 'created_at' => $now]);

        // Второй выполненный заказ.
        $completed2 = $this->makeOrder($admin, $master, OrderStatus::COMPLETED, [
            'final_price' => 4000, 'parts_cost' => 500, 'master_payout' => 1500, 'completed_at' => $now,
        ]);
        OrderStatusHistory::create(['order_id' => $completed2->id, 'old_status' => 'NEW', 'new_status' => 'ASSIGNED', 'created_at' => $now]);
        OrderStatusHistory::create(['order_id' => $completed2->id, 'old_status' => 'ASSIGNED', 'new_status' => 'ACCEPTED', 'changed_by_user_id' => $masterUser->id, 'created_at' => $now]);

        // Назначен, но отклонён этим же мастером — не должен считаться выполненным.
        $declinedOrder = $this->makeOrder($admin, $master, OrderStatus::MASTER_DECLINED);
        OrderStatusHistory::create(['order_id' => $declinedOrder->id, 'old_status' => 'NEW', 'new_status' => 'ASSIGNED', 'created_at' => $now]);
        OrderStatusHistory::create(['order_id' => $declinedOrder->id, 'old_status' => 'ASSIGNED', 'new_status' => 'MASTER_DECLINED', 'changed_by_user_id' => $masterUser->id, 'created_at' => $now]);

        $stats = app(MasterStatsCalculator::class)->calculate($master, $now->copy()->startOfDay(), $now->copy()->endOfDay());

        $this->assertSame(3, $stats['assigned']);
        $this->assertSame(2, $stats['accepted']);
        $this->assertSame(1, $stats['declined']);
        $this->assertSame(2, $stats['completed']);
        $this->assertSame(1, $stats['paid']);
        $this->assertSame(10000, $stats['revenue']);
        $this->assertSame(5000, $stats['avg_check']);
        $this->assertSame(1500, $stats['parts_cost']);
        $this->assertSame(4000, $stats['master_payout']);
        $this->assertSame(100, $stats['completion_rate']); // 2 completed / 2 accepted
        $this->assertSame(33, $stats['decline_rate']); // 1 declined / 3 assigned
    }

    public function test_master_stats_outside_period_are_excluded(): void
    {
        $admin = User::factory()->admin()->create();
        $masterUser = User::factory()->master()->create();
        $master = Master::factory()->for($masterUser)->create();

        $old = $this->makeOrder($admin, $master, OrderStatus::PAID, [
            'final_price' => 9000, 'completed_at' => now()->subDays(40),
        ]);
        OrderStatusHistory::create(['order_id' => $old->id, 'new_status' => 'ASSIGNED', 'created_at' => now()->subDays(40)]);

        $stats = app(MasterStatsCalculator::class)->calculate($master, now()->subDays(6)->startOfDay(), now()->endOfDay());

        $this->assertSame(0, $stats['assigned']);
        $this->assertSame(0, $stats['completed']);
        $this->assertSame(0, $stats['revenue']);
        $this->assertNull($stats['completion_rate']);
    }

    public function test_declined_order_credited_to_declining_master_not_new_one(): void
    {
        $admin = User::factory()->admin()->create();
        $firstUser = User::factory()->master()->create();
        $first = Master::factory()->for($firstUser)->create();
        $secondUser = User::factory()->master()->create();
        $second = Master::factory()->for($secondUser)->create();

        $now = now();
        // Заказ сейчас у второго мастера (переназначен), но отказ зафиксирован за первым.
        $order = $this->makeOrder($admin, $second, OrderStatus::ASSIGNED);
        OrderStatusHistory::create(['order_id' => $order->id, 'new_status' => 'ASSIGNED', 'created_at' => $now]);
        OrderStatusHistory::create(['order_id' => $order->id, 'new_status' => 'MASTER_DECLINED', 'changed_by_user_id' => $firstUser->id, 'created_at' => $now]);
        OrderStatusHistory::create(['order_id' => $order->id, 'new_status' => 'ASSIGNED', 'created_at' => $now]);

        $firstStats = app(MasterStatsCalculator::class)->calculate($first, $now->copy()->startOfDay(), $now->copy()->endOfDay());
        $secondStats = app(MasterStatsCalculator::class)->calculate($second, $now->copy()->startOfDay(), $now->copy()->endOfDay());

        $this->assertSame(1, $firstStats['declined']);
        $this->assertSame(0, $secondStats['declined']);
    }

    public function test_order_stats_aggregate_across_masters(): void
    {
        $admin = User::factory()->admin()->create();
        $masterUser = User::factory()->master()->create();
        $master = Master::factory()->for($masterUser)->create();

        $now = now();
        $this->makeOrder($admin, $master, OrderStatus::PAID, ['final_price' => 5000, 'completed_at' => $now]);
        $this->makeOrder($admin, $master, OrderStatus::WAITING_PART);

        $stats = app(OrderStatsCalculator::class)->calculate($now->copy()->startOfDay(), $now->copy()->endOfDay());

        $this->assertSame(2, $stats['new_orders']);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(5000, $stats['revenue']);
        $this->assertSame(5000, $stats['avg_check']);
        $this->assertSame(1, $stats['waiting_parts']);
    }

    public function test_master_stats_screen_shows_na_when_nothing_assigned(): void
    {
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 31001]);
        Master::factory()->for($masterUser)->create(['name' => 'Ахмед']);

        $this->postMessage($masterUser->telegram_user_id, 'Моя статистика');
        $this->postCallback($masterUser->telegram_user_id, 'stats:today');

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $masterUser->telegram_user_id
            && str_contains($r['text'] ?? '', 'Мастер: Ахмед')
            && str_contains($r['text'] ?? '', 'Completion rate: N/A')
        );
    }

    public function test_admin_stats_screen_shows_overall_and_per_master_breakdown(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 31002]);
        $masterUser = User::factory()->master()->create();
        $master = Master::factory()->for($masterUser)->create(['name' => 'Иван']);

        $this->makeOrder($admin, $master, OrderStatus::PAID, ['final_price' => 7000, 'completed_at' => now()]);

        $this->postMessage($admin->telegram_user_id, 'Статистика');
        $this->postCallback($admin->telegram_user_id, 'stats:today');

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($r['text'] ?? '', 'Выручка: 7000')
        );

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($r['text'] ?? '', 'По мастерам')
            && str_contains($r['text'] ?? '', 'Иван')
        );
    }

    public function test_custom_period_flow_via_two_dates(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 31003]);
        $masterUser = User::factory()->master()->create();
        $master = Master::factory()->for($masterUser)->create();

        $target = now()->subDays(3);
        $this->makeOrder($admin, $master, OrderStatus::PAID, ['final_price' => 3000, 'completed_at' => $target]);

        $this->postMessage($admin->telegram_user_id, 'Статистика');
        $this->postCallback($admin->telegram_user_id, 'stats:custom');
        $this->postMessage($admin->telegram_user_id, now()->subDays(5)->format('d.m'));
        $this->postMessage($admin->telegram_user_id, now()->format('d.m'));

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($r['text'] ?? '', 'Выручка: 3000')
        );

        $this->assertDatabaseMissing('pending_inputs', ['user_id' => $admin->id]);
    }

    public function test_custom_period_rejects_end_before_start(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 31004]);

        $this->postMessage($admin->telegram_user_id, 'Статистика');
        $this->postCallback($admin->telegram_user_id, 'stats:custom');
        $this->postMessage($admin->telegram_user_id, now()->format('d.m'));
        $this->postMessage($admin->telegram_user_id, now()->subDays(5)->format('d.m'));

        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'раньше даты начала'));
    }
}
