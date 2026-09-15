<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\ApplianceType;
use App\Models\Order;
use App\Models\TimeSlot;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTransitionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    private function makeOrder(OrderStatus $status = OrderStatus::NEW): Order
    {
        $admin = User::factory()->admin()->create();
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
            'created_by' => $admin->id,
        ]);

        $order->update(['status' => $status]);

        return $order;
    }

    public function test_request_without_key_is_rejected(): void
    {
        $order = $this->makeOrder();

        $this->postJson("/api/v1/orders/{$order->id}/transition", ['target_status' => 'ASSIGNED'])
            ->assertStatus(401);
    }

    public function test_valid_transition_succeeds(): void
    {
        $order = $this->makeOrder(OrderStatus::NEW);

        $response = $this->postJson(
            "/api/v1/orders/{$order->id}/transition",
            ['target_status' => 'ASSIGNED', 'comment' => 'via API'],
            ['X-Internal-Api-Key' => 'test-internal-key']
        );

        $response->assertOk()->assertJson(['status' => 'ASSIGNED']);
        $this->assertSame(OrderStatus::ASSIGNED, $order->fresh()->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'new_status' => 'ASSIGNED',
            'comment' => 'via API',
        ]);
    }

    public function test_invalid_transition_returns_conflict(): void
    {
        $order = $this->makeOrder(OrderStatus::NEW);

        $response = $this->postJson(
            "/api/v1/orders/{$order->id}/transition",
            ['target_status' => 'COMPLETED'],
            ['X-Internal-Api-Key' => 'test-internal-key']
        );

        $response->assertStatus(409);
        $this->assertSame(OrderStatus::NEW, $order->fresh()->status);
    }

    public function test_unknown_status_is_rejected(): void
    {
        $order = $this->makeOrder();

        $this->postJson(
            "/api/v1/orders/{$order->id}/transition",
            ['target_status' => 'NOT_A_STATUS'],
            ['X-Internal-Api-Key' => 'test-internal-key']
        )->assertStatus(422);
    }
}
