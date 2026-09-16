<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\ApplianceType;
use App\Models\Brand;
use App\Models\GeoZone;
use App\Models\Master;
use App\Models\Order;
use App\Models\TimeSlot;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderCreationFlowTest extends TestCase
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

    public function test_admin_creates_order_end_to_end_and_master_gets_notified(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 1001]);

        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();
        $liebherr = Brand::where('name', 'Liebherr')->firstOrFail();
        $odintsovo = GeoZone::where('name', 'Одинцово')->firstOrFail();
        $slot = TimeSlot::where('label', '15:00–18:00')->firstOrFail();

        $masterUser = User::factory()->master()->create(['telegram_user_id' => 2001, 'name' => 'Ахмед']);
        $master = Master::factory()->for($masterUser)->create(['name' => 'Ахмед']);
        $master->applianceTypes()->attach($fridge->id);
        $master->brands()->attach($liebherr->id);
        $master->geoZones()->attach($odintsovo->id);

        $this->postMessage($admin->telegram_user_id, 'Новая заявка');
        $this->assertDatabaseCount('order_drafts', 1);

        $this->postCallback($admin->telegram_user_id, "draft:appliance_type:{$fridge->id}");
        $this->postCallback($admin->telegram_user_id, "draft:brand:{$liebherr->id}");
        $this->postMessage($admin->telegram_user_id, 'CNef 4815');

        $symptom = \App\Models\Symptom::where('appliance_type_id', $fridge->id)->where('text', 'Не морозит')->firstOrFail();
        $this->postCallback($admin->telegram_user_id, "draft:symptom:{$symptom->id}");
        $this->postCallback($admin->telegram_user_id, 'draft:description:skip');

        $this->postMessage($admin->telegram_user_id, 'Иван');
        $this->postMessage($admin->telegram_user_id, '+7 916 111-22-33');
        $this->postMessage($admin->telegram_user_id, 'Одинцово, ул. Молодёжная, 14');

        $this->postCallback($admin->telegram_user_id, "draft:zone:{$odintsovo->id}");
        $this->postCallback($admin->telegram_user_id, 'draft:date:tomorrow');
        $this->postCallback($admin->telegram_user_id, "draft:slot:{$slot->id}");
        $this->postCallback($admin->telegram_user_id, "draft:master:{$master->id}");

        $this->assertDatabaseHas('order_drafts', ['step' => 'CONFIRM']);

        $this->postCallback($admin->telegram_user_id, 'draft:confirm:create');

        $this->assertDatabaseCount('order_drafts', 0);
        $this->assertDatabaseCount('orders', 1);

        $order = Order::query()->first();

        $this->assertSame(OrderStatus::ASSIGNED, $order->status);
        $this->assertSame($master->id, $order->master_id);
        $this->assertSame('Иван', $order->customer_name);
        $this->assertSame('+79161112233', $order->customer_phone);
        $this->assertSame('CNef 4815', $order->model);
        $this->assertSame('Не морозит', $order->symptom);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'old_status' => 'NEW',
            'new_status' => 'ASSIGNED',
        ]);

        Http::assertSent(function ($request) use ($master, $order) {
            if ($request->url() !== 'https://api.telegram.org/bottest:token/sendMessage') {
                return false;
            }

            if ((int) $request['chat_id'] !== $master->user->telegram_user_id) {
                return false;
            }

            return str_contains($request['text'], $order->code())
                && str_contains($request['text'], 'Иван')
                && str_contains($request['reply_markup'], 'order:accept:'.$order->number)
                && str_contains($request['reply_markup'], 'order:decline:'.$order->number);
        });

        Http::assertSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($request['text'] ?? '', 'создана и назначена мастеру Ахмед')
        );
    }

    public function test_master_selection_falls_back_when_no_exact_match(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 1002]);
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();

        $masterUser = User::factory()->master()->create(['telegram_user_id' => 2002, 'name' => 'Муса']);
        $master = Master::factory()->for($masterUser)->create(['name' => 'Муса']);
        $master->applianceTypes()->attach($fridge->id);
        // Без брендов и без зоны — точного совпадения быть не может.

        $this->postMessage($admin->telegram_user_id, 'Новая заявка');
        $this->postCallback($admin->telegram_user_id, "draft:appliance_type:{$fridge->id}");
        $this->postCallback($admin->telegram_user_id, 'draft:brand:unknown');
        $this->postCallback($admin->telegram_user_id, 'draft:model:skip');

        $symptom = \App\Models\Symptom::where('appliance_type_id', $fridge->id)->firstOrFail();
        $this->postCallback($admin->telegram_user_id, "draft:symptom:{$symptom->id}");
        $this->postCallback($admin->telegram_user_id, 'draft:description:skip');
        $this->postMessage($admin->telegram_user_id, 'Клиент');
        $this->postMessage($admin->telegram_user_id, '+79990001122');
        $this->postMessage($admin->telegram_user_id, 'Где-то в Москве');
        $this->postCallback($admin->telegram_user_id, 'draft:zone:skip');
        $this->postCallback($admin->telegram_user_id, 'draft:date:today');
        $this->postCallback($admin->telegram_user_id, 'draft:slot:custom');
        $this->postMessage($admin->telegram_user_id, '19:00–20:00');

        Http::assertSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($request['text'] ?? '', 'Точных совпадений нет')
            && str_contains($request['text'] ?? '', 'Муса')
        );
    }

    public function test_custom_date_input_is_accepted(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 1005]);
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();

        $this->postMessage($admin->telegram_user_id, 'Новая заявка');
        $this->postCallback($admin->telegram_user_id, "draft:appliance_type:{$fridge->id}");
        $this->postCallback($admin->telegram_user_id, 'draft:brand:unknown');
        $this->postCallback($admin->telegram_user_id, 'draft:model:skip');
        $symptom = \App\Models\Symptom::where('appliance_type_id', $fridge->id)->firstOrFail();
        $this->postCallback($admin->telegram_user_id, "draft:symptom:{$symptom->id}");
        $this->postCallback($admin->telegram_user_id, 'draft:description:skip');
        $this->postMessage($admin->telegram_user_id, 'Клиент');
        $this->postMessage($admin->telegram_user_id, '+79990001122');
        $this->postMessage($admin->telegram_user_id, 'Где-то в Москве');
        $this->postCallback($admin->telegram_user_id, 'draft:zone:skip');

        $this->postCallback($admin->telegram_user_id, 'draft:date:custom');
        $futureDate = now()->addDays(12);
        $this->postMessage($admin->telegram_user_id, $futureDate->format('d.m'));

        $this->assertDatabaseHas('order_drafts', ['step' => 'TIME_SLOT']);
        $draft = \App\Models\OrderDraft::query()->first();
        $this->assertSame($futureDate->toDateString(), $draft->get('visit_date'));
    }

    public function test_invalid_phone_is_rejected_with_retry_prompt(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 1003]);
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();

        $this->postMessage($admin->telegram_user_id, 'Новая заявка');
        $this->postCallback($admin->telegram_user_id, "draft:appliance_type:{$fridge->id}");
        $this->postCallback($admin->telegram_user_id, 'draft:brand:unknown');
        $this->postCallback($admin->telegram_user_id, 'draft:model:skip');
        $symptom = \App\Models\Symptom::where('appliance_type_id', $fridge->id)->firstOrFail();
        $this->postCallback($admin->telegram_user_id, "draft:symptom:{$symptom->id}");
        $this->postCallback($admin->telegram_user_id, 'draft:description:skip');
        $this->postMessage($admin->telegram_user_id, 'Клиент');

        $this->postMessage($admin->telegram_user_id, 'абв');

        $this->assertDatabaseHas('order_drafts', ['step' => 'CUSTOMER_PHONE']);

        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'не телефон'));
    }

    public function test_menu_command_exits_stuck_draft_instead_of_repeating_validation_error(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 1006]);
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();

        $this->postMessage($admin->telegram_user_id, 'Новая заявка');
        $this->postCallback($admin->telegram_user_id, "draft:appliance_type:{$fridge->id}");
        $this->postCallback($admin->telegram_user_id, 'draft:brand:unknown');
        $this->postCallback($admin->telegram_user_id, 'draft:model:skip');
        $symptom = \App\Models\Symptom::where('appliance_type_id', $fridge->id)->firstOrFail();
        $this->postCallback($admin->telegram_user_id, "draft:symptom:{$symptom->id}");
        $this->postCallback($admin->telegram_user_id, 'draft:description:skip');
        $this->postMessage($admin->telegram_user_id, 'Клиент');

        // Раньше это застревало навсегда — ни кнопка меню, ни /start не прерывали
        // черновик, бот раз за разом повторял "не телефон" (см. живой QA-прогон
        // 16.09.2026, vault/Фазы/Фаза 07 — Прод-готовность.md).
        $this->postMessage($admin->telegram_user_id, 'телефон123');
        $this->assertDatabaseHas('order_drafts', ['step' => 'CUSTOMER_PHONE']);

        $this->postMessage($admin->telegram_user_id, 'Активные');

        $this->assertDatabaseCount('order_drafts', 0);
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Черновик заявки отменён'));
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Активных заказов нет'));
    }

    public function test_draft_is_discarded_after_expiry(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 1004]);

        $this->postMessage($admin->telegram_user_id, 'Новая заявка');
        $this->assertDatabaseCount('order_drafts', 1);

        \App\Models\OrderDraft::query()->update(['expires_at' => now()->subMinute()]);

        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();
        $this->postCallback($admin->telegram_user_id, "draft:appliance_type:{$fridge->id}");

        $this->assertDatabaseCount('order_drafts', 0);
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'истёк'));
    }
}
