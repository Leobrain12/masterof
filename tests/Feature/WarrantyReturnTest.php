<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\ApplianceType;
use App\Models\Master;
use App\Models\Order;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Orders\MasterStatsCalculator;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Точечные проверки гарантийного обращения (ТЗ п.64-65), которые не покрыты
 * основным happy-path в QaScenariosTest::test_scenario_06: доступ по роли и
 * то, что MasterStatsCalculator тоже исключает гарантию из обычных счётчиков
 * (OrderStatsCalculator это же покрывает в самом сценарии 6).
 */
class WarrantyReturnTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_URL = '/api/telegram/webhook';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Http::fake();
    }

    private function sendUpdate(array $update): void
    {
        $this->postJson(self::WEBHOOK_URL, $update, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }

    private function callbackUpdate(int $telegramUserId, string $data): array
    {
        return [
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => (string) random_int(1, PHP_INT_MAX),
                'from' => ['id' => $telegramUserId, 'is_bot' => false, 'first_name' => 'Test'],
                'message' => ['chat' => ['id' => $telegramUserId, 'type' => 'private'], 'message_id' => 1],
                'data' => $data,
            ],
        ];
    }

    public function test_master_cannot_start_warranty_return(): void
    {
        $admin = User::factory()->admin()->create();
        $masterUser = User::factory()->master()->create();
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeOrder($admin, $master, OrderStatus::COMPLETED);

        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:warranty:{$order->number}"));

        $this->assertSame(0, Order::query()->where('warranty_parent_order_id', $order->id)->count());
    }

    public function test_master_stats_exclude_warranty_from_regular_counters_but_count_it_separately(): void
    {
        $admin = User::factory()->admin()->create();
        $masterUser = User::factory()->master()->create();
        $master = Master::factory()->for($masterUser)->create();

        $regular = $this->makeOrder($admin, $master, OrderStatus::COMPLETED);
        $regular->update(['final_price' => 5000, 'completed_at' => now()]);

        $warranty = $this->makeOrder($admin, $master, OrderStatus::COMPLETED);
        $warranty->update([
            'warranty_parent_order_id' => $regular->id,
            'final_price' => 0,
            'parts_cost' => 400,
            'master_payout' => 300,
            'completed_at' => now(),
        ]);

        $stats = app(MasterStatsCalculator::class)->calculate($master, now()->subDay(), now()->addDay());

        $this->assertSame(1, $stats['completed']);
        $this->assertSame(5000, $stats['revenue']);
        $this->assertSame(1, $stats['warranty']);
    }

    private function makeOrder(User $admin, Master $master, OrderStatus $status): Order
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

        $order->update(['status' => $status]);

        return $order;
    }
}
