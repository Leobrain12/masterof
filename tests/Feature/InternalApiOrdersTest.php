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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ТЗ п.88 — Internal API эндпоинты, которые CRM/сайт использует помимо
 * уже покрытого /transition (см. OrderTransitionApiTest). Каждый эндпоинт
 * здесь зеркалит соответствующий бот-flow (см. комментарии в контроллерах),
 * поэтому тесты сверяют именно то, что зеркало не разошлось с оригиналом.
 */
class InternalApiOrdersTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = ['X-Internal-Api-Key' => 'test-internal-key'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Http::fake();
    }

    public function test_create_order_via_api(): void
    {
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();
        $slot = TimeSlot::first();
        $admin = User::factory()->admin()->create();

        $response = $this->postJson('/api/v1/orders', [
            'customer_name' => 'Иван Клиентов',
            'customer_phone' => '+79990001122',
            'appliance_type_id' => $fridge->id,
            'symptom' => 'Не морозит',
            'address' => 'Москва, ул. Ленина, 1',
            'visit_date' => now()->addDay()->toDateString(),
            'time_slot_label' => $slot->label,
            'time_slot_id' => $slot->id,
            'lead_id' => (string) \Illuminate\Support\Str::uuid(),
            'source' => 'amocrm',
        ], self::KEY);

        $response->assertCreated()->assertJsonPath('data.status', 'NEW')->assertJsonPath('data.source', 'amocrm');

        $order = Order::query()->latest('created_at')->firstOrFail();
        $this->assertNull($order->created_by);
        $this->assertNull($order->master_id);
        $this->assertSame(1, $order->visits()->count());
        $this->assertNull($order->visits()->first()->master_id);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', 'через API')
        );
    }

    public function test_create_order_requires_appliance_type(): void
    {
        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Иван',
            'customer_phone' => '+79990001122',
            'symptom' => 'Не морозит',
            'address' => 'Москва',
            'visit_date' => now()->toDateString(),
            'time_slot_label' => 'Утро',
        ], self::KEY)->assertStatus(422);
    }

    public function test_show_order(): void
    {
        $order = $this->makeOrder();

        $this->getJson("/api/v1/orders/{$order->id}", self::KEY)
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.number', $order->number);
    }

    public function test_update_order(): void
    {
        $order = $this->makeOrder();

        $this->patchJson("/api/v1/orders/{$order->id}", ['customer_name' => 'Новое имя'], self::KEY)
            ->assertOk()
            ->assertJsonPath('data.customer_name', 'Новое имя');

        $this->assertSame('Новое имя', $order->fresh()->customer_name);
    }

    public function test_search_orders_by_status(): void
    {
        $this->makeOrder(OrderStatus::NEW);
        $this->makeOrder(OrderStatus::ASSIGNED);

        $response = $this->getJson('/api/v1/orders/search?status=NEW', self::KEY)->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('NEW', $data[0]['status']);
    }

    public function test_assign_master_to_new_order(): void
    {
        $order = $this->makeOrder(OrderStatus::NEW);
        $master = Master::factory()->create();

        $this->postJson("/api/v1/orders/{$order->id}/assign", ['master_id' => $master->id], self::KEY)
            ->assertOk()
            ->assertJsonPath('data.status', 'ASSIGNED');

        $this->assertSame($master->id, $order->fresh()->master_id);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage'));
    }

    public function test_assign_fails_for_already_assigned_order(): void
    {
        $order = $this->makeOrder(OrderStatus::ASSIGNED);
        $master = Master::factory()->create();

        $this->postJson("/api/v1/orders/{$order->id}/assign", ['master_id' => $master->id], self::KEY)
            ->assertStatus(409);
    }

    public function test_schedule_next_visit_requires_waiting_part_status(): void
    {
        $order = $this->makeOrder(OrderStatus::ASSIGNED);

        $this->postJson("/api/v1/orders/{$order->id}/visits", [
            'visit_date' => now()->addDays(3)->toDateString(),
            'time_slot_label' => 'Вечер',
        ], self::KEY)->assertStatus(409);
    }

    public function test_schedule_next_visit(): void
    {
        $order = $this->makeOrder(OrderStatus::WAITING_PART, withVisit: true);

        $response = $this->postJson("/api/v1/orders/{$order->id}/visits", [
            'visit_date' => now()->addDays(3)->toDateString(),
            'time_slot_label' => 'Вечер',
        ], self::KEY);

        $response->assertOk()->assertJsonPath('data.status', 'ACCEPTED');

        $this->assertSame(2, $order->visits()->count());
        $this->assertSame('COMPLETED', $order->visits()->where('visit_number', 1)->first()->status);
        $this->assertSame('SCHEDULED', $order->visits()->where('visit_number', 2)->first()->status);
    }

    public function test_submit_work_report_requires_in_progress_status(): void
    {
        $order = $this->makeOrder(OrderStatus::ASSIGNED);

        $this->postJson("/api/v1/orders/{$order->id}/work-report", [
            'work_description' => 'Заменили насос',
            'labor_price' => 2000,
            'parts_sell_price' => 1500,
            'parts_cost' => 900,
            'master_payout' => 1200,
        ], self::KEY)->assertStatus(409);
    }

    public function test_submit_work_report(): void
    {
        $order = $this->makeOrder(OrderStatus::IN_PROGRESS, withVisit: true);

        $response = $this->postJson("/api/v1/orders/{$order->id}/work-report", [
            'work_description' => 'Заменили насос',
            'labor_price' => 2000,
            'parts_sell_price' => 1500,
            'parts_cost' => 900,
            'master_payout' => 1200,
        ], self::KEY);

        $response->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.final_price', 3500);

        $this->assertDatabaseHas('work_reports', [
            'order_id' => $order->id,
            'final_price' => 3500,
        ]);
        $this->assertSame('COMPLETED', $order->visits()->first()->status);
    }

    public function test_upload_media(): void
    {
        $order = $this->makeOrder(OrderStatus::IN_PROGRESS);
        // create(), не image() — не требует ext-gd в контейнере, а mimetypes-правило
        // всё равно проверяется по заявленному типу, не по валидности картинки.
        $file = UploadedFile::fake()->create('after.jpg', 500, 'image/jpeg');

        $response = $this->post("/api/v1/orders/{$order->id}/media", [
            'file' => $file,
            'stage' => 'AFTER',
        ], self::KEY);

        $response->assertCreated()->assertJsonPath('media_type', 'PHOTO')->assertJsonPath('stage', 'AFTER');

        $this->assertDatabaseHas('order_media', [
            'order_id' => $order->id,
            'uploaded_by_user_id' => null,
        ]);
    }

    public function test_record_full_payment_transitions_to_paid(): void
    {
        $order = $this->makeOrder(OrderStatus::COMPLETED);
        $order->update(['final_price' => 3500]);

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['amount' => 3500], self::KEY)
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID');

        $this->assertSame(3500, $order->fresh()->amount_paid);
    }

    public function test_record_partial_payment_stays_completed(): void
    {
        $order = $this->makeOrder(OrderStatus::COMPLETED);
        $order->update(['final_price' => 3500]);

        $this->postJson("/api/v1/orders/{$order->id}/payments", ['amount' => 1000], self::KEY)
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertSame(1000, $order->fresh()->amount_paid);
    }

    public function test_masters_index(): void
    {
        Master::factory()->create(['name' => 'Пётр']);

        $response = $this->getJson('/api/v1/masters', self::KEY)->assertOk();

        $this->assertContains('Пётр', $response->json('data.*.name'));
    }

    public function test_master_stats(): void
    {
        $master = Master::factory()->create();

        $this->getJson(
            "/api/v1/masters/{$master->id}/stats?from=".now()->subDays(7)->toDateString().'&to='.now()->toDateString(),
            self::KEY
        )->assertOk()->assertJsonStructure(['master_id', 'from', 'to', 'assigned', 'completed', 'revenue']);
    }

    public function test_overall_stats(): void
    {
        $this->getJson(
            '/api/v1/stats?from='.now()->subDays(7)->toDateString().'&to='.now()->toDateString(),
            self::KEY
        )->assertOk()->assertJsonStructure(['from', 'to', 'new_orders', 'completed', 'revenue']);
    }

    private function makeOrder(OrderStatus $status = OrderStatus::NEW, bool $withVisit = false): Order
    {
        $admin = User::factory()->admin()->create();
        $master = Master::factory()->create();
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
            'master_id' => $status === OrderStatus::NEW ? null : $master->id,
            'created_by' => $admin->id,
        ]);

        $order->update(['status' => $status]);

        if ($withVisit) {
            OrderVisit::query()->create([
                'order_id' => $order->id,
                'visit_number' => 1,
                'visit_date' => $order->visit_date,
                'time_slot_label' => $order->time_slot_label,
                'master_id' => $order->master_id,
                'status' => 'SCHEDULED',
                'reason' => 'Диагностика',
            ]);
        }

        return $order;
    }
}
