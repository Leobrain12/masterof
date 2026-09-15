<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\ApplianceType;
use App\Models\Order;
use App\Models\TimeSlot;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Реальные лимиты (300/мин на вебхук, 60/мин на internal API — см. AppServiceProvider)
 * слишком щедрые, чтобы гонять сотни запросов в тесте. Здесь лимитер временно
 * переопределяется на 2 запроса — проверяем сам факт, что throttle подключён
 * и считает запросы независимо от исхода аутентификации, а не точные цифры.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_telegram_webhook_is_rate_limited(): void
    {
        RateLimiter::for('telegram-webhook', fn (Request $r) => Limit::perMinute(2)->by($r->ip()));

        for ($i = 0; $i < 2; $i++) {
            $response = $this->postJson('/api/telegram/webhook', ['update_id' => $i]);
            $this->assertNotSame(429, $response->status());
        }

        // Третий запрос за минуту — лимит исчерпан, даже без секрета (throttle
        // стоит первым в цепочке middleware, до VerifyTelegramWebhookSecret).
        $this->postJson('/api/telegram/webhook', ['update_id' => 999])
            ->assertStatus(429);
    }

    public function test_internal_api_is_rate_limited_per_key(): void
    {
        RateLimiter::for(
            'internal-api',
            fn (Request $r) => Limit::perMinute(2)->by($r->header('X-Internal-Api-Key') ?: $r->ip())
        );

        $order = $this->makeOrder();

        for ($i = 0; $i < 2; $i++) {
            $response = $this->postJson(
                "/api/v1/orders/{$order->id}/transition",
                ['target_status' => 'ASSIGNED'],
                ['X-Internal-Api-Key' => 'wrong-key']
            );
            $this->assertNotSame(429, $response->status());
        }

        $this->postJson(
            "/api/v1/orders/{$order->id}/transition",
            ['target_status' => 'ASSIGNED'],
            ['X-Internal-Api-Key' => 'wrong-key']
        )->assertStatus(429);
    }

    public function test_internal_api_limit_is_scoped_per_key_not_shared(): void
    {
        RateLimiter::for(
            'internal-api',
            fn (Request $r) => Limit::perMinute(1)->by($r->header('X-Internal-Api-Key') ?: $r->ip())
        );

        $order = $this->makeOrder();

        $this->postJson(
            "/api/v1/orders/{$order->id}/transition",
            ['target_status' => 'ASSIGNED'],
            ['X-Internal-Api-Key' => 'key-one']
        )->assertStatus(401);

        // Другой ключ — свой собственный лимит, не делит бюджет с первым.
        $this->postJson(
            "/api/v1/orders/{$order->id}/transition",
            ['target_status' => 'ASSIGNED'],
            ['X-Internal-Api-Key' => 'key-two']
        )->assertStatus(401);
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
}
