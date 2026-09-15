<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\SyncOrderToCrm;
use App\Models\ApplianceType;
use App\Models\Master;
use App\Models\Order;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Crm\CrmAdapter;
use App\Services\Crm\LogCrmAdapter;
use App\Services\Orders\OrderStatusMachine;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * ТЗ п.85-87 — CrmAdapter/SyncOrderToCrm. Три вещи важно доказать: (1) синк
 * реально запускается на каждом переходе статуса, а не только по обещанию
 * в комментарии; (2) job умеет сохранить crm_id, когда адаптер его вернул;
 * (3) падение CRM/очереди не ломает сам переход заказа — п.87 в чистом виде.
 */
class CrmSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_default_crm_adapter_binding_is_log_adapter(): void
    {
        $this->assertInstanceOf(LogCrmAdapter::class, app(CrmAdapter::class));
    }

    public function test_log_adapter_does_not_throw_and_returns_null(): void
    {
        $order = $this->makeOrder();

        $this->assertNull(app(LogCrmAdapter::class)->syncOrder($order));
    }

    public function test_transition_dispatches_sync_job(): void
    {
        Bus::fake();

        $order = $this->makeOrder();
        app(OrderStatusMachine::class)->transition(
            $order,
            OrderStatus::ASSIGNED,
            actor: null,
            attributes: ['master_id' => Master::factory()->create()->id],
        );

        Bus::assertDispatched(SyncOrderToCrm::class, fn (SyncOrderToCrm $job) => $job->order->id === $order->id);
    }

    public function test_job_saves_crm_id_returned_by_adapter(): void
    {
        $this->app->bind(CrmAdapter::class, fn () => new class implements CrmAdapter
        {
            public function syncOrder(Order $order): ?string
            {
                return 'crm-external-42';
            }
        });

        $order = $this->makeOrder();
        $master = Master::factory()->create();

        app(OrderStatusMachine::class)->transition(
            $order,
            OrderStatus::ASSIGNED,
            actor: null,
            attributes: ['master_id' => $master->id],
        );

        $this->assertSame('crm-external-42', $order->fresh()->crm_id);
    }

    public function test_crm_outage_does_not_break_order_transition(): void
    {
        $this->app->bind(CrmAdapter::class, fn () => new class implements CrmAdapter
        {
            public function syncOrder(Order $order): ?string
            {
                throw new RuntimeException('CRM unreachable');
            }
        });

        $order = $this->makeOrder();
        $master = Master::factory()->create();

        try {
            app(OrderStatusMachine::class)->transition(
                $order,
                OrderStatus::ASSIGNED,
                actor: null,
                attributes: ['master_id' => $master->id],
            );
        } catch (Throwable $e) {
            $this->fail('Переход заказа не должен падать из-за недоступной CRM: '.$e->getMessage());
        }

        // Статус реально сменился в БД — падение синка не откатило переход
        // и не помешало ему зафиксироваться (ТЗ п.87).
        $this->assertSame(OrderStatus::ASSIGNED, $order->fresh()->status);
    }

    private function makeOrder(): Order
    {
        $admin = User::factory()->admin()->create();
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();
        $slot = TimeSlot::first();

        return Order::create([
            'customer_name' => 'Клиент',
            'customer_phone' => '+79990001122',
            'appliance_type_id' => $fridge->id,
            'symptom' => 'Не морозит',
            'address' => 'Москва',
            'visit_date' => now()->toDateString(),
            'time_slot_label' => $slot->label,
            'time_slot_id' => $slot->id,
            'status' => OrderStatus::NEW,
            'created_by' => $admin->id,
        ]);
    }
}
