<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\ApplianceType;
use App\Models\Master;
use App\Models\Order;
use App\Models\OrderMedia;
use App\Models\OrderVisit;
use App\Models\TimeSlot;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 10 обязательных QA-сценариев из ТЗ п.131 — по одному тесту на каждый,
 * прогоняются через настоящий webhook-эндпоинт, а не напрямую через сервисы,
 * чтобы проверять систему так же, как её будет проверять живой Telegram-апдейт.
 *
 * Сценарии 6 (гарантийный возврат) и 7 (CRM недоступна) физически нечем
 * тестировать — обе фичи осознанно не реализованы в этом проходе (см.
 * vault/Открытые вопросы.md и vault/Фазы/Фаза 03). Тест на них будет написан
 * вместе с самой фичей, а не раньше.
 */
class QaScenariosTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_URL = '/api/telegram/webhook';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('media');

        Http::fake([
            'api.telegram.org/bottest:token/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'photos/file.jpg'],
            ]),
            'api.telegram.org/file/bottest:token/*' => Http::response('fake-bytes'),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    private function sendUpdate(array $update): void
    {
        $this->postJson(self::WEBHOOK_URL, $update, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }

    private function messageUpdate(int $telegramUserId, string $text, ?int $updateId = null): array
    {
        return [
            'update_id' => $updateId ?? random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => 1,
                'from' => ['id' => $telegramUserId, 'is_bot' => false, 'first_name' => 'Test'],
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                'text' => $text,
            ],
        ];
    }

    private function callbackUpdate(int $telegramUserId, string $data, ?int $updateId = null): array
    {
        return [
            'update_id' => $updateId ?? random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => (string) random_int(1, PHP_INT_MAX),
                'from' => ['id' => $telegramUserId, 'is_bot' => false, 'first_name' => 'Test'],
                'message' => ['chat' => ['id' => $telegramUserId, 'type' => 'private'], 'message_id' => 1],
                'data' => $data,
            ],
        ];
    }

    private function photoUpdate(int $telegramUserId, string $fileId): array
    {
        return [
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => 1,
                'from' => ['id' => $telegramUserId, 'is_bot' => false, 'first_name' => 'Test'],
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                'photo' => [
                    ['file_id' => "{$fileId}-s", 'file_unique_id' => 'us', 'width' => 100, 'height' => 100],
                    ['file_id' => $fileId, 'file_unique_id' => 'ul', 'width' => 800, 'height' => 600],
                ],
            ],
        ];
    }

    private function videoUpdate(int $telegramUserId, string $fileId): array
    {
        return [
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => 1,
                'from' => ['id' => $telegramUserId, 'is_bot' => false, 'first_name' => 'Test'],
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                'video' => ['file_id' => $fileId, 'file_unique_id' => 'vu', 'width' => 1280, 'height' => 720, 'duration' => 20, 'file_size' => 500_000],
            ],
        ];
    }

    /**
     * Сценарий 1: ADMIN создаёт → MASTER принимает → ремонт выполнен → фото + видео → оплата.
     */
    public function test_scenario_01_full_lifecycle_from_creation_to_payment(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 90101]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 90102, 'name' => 'Ахмед']);
        $master = Master::factory()->for($masterUser)->create(['name' => 'Ахмед']);
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();
        $master->applianceTypes()->attach($fridge->id);

        // Создание.
        $this->sendUpdate($this->messageUpdate($admin->telegram_user_id, 'Новая заявка'));
        $this->sendUpdate($this->callbackUpdate($admin->telegram_user_id, "draft:appliance_type:{$fridge->id}"));
        $this->sendUpdate($this->callbackUpdate($admin->telegram_user_id, 'draft:brand:unknown'));
        $this->sendUpdate($this->callbackUpdate($admin->telegram_user_id, 'draft:model:skip'));
        $symptom = \App\Models\Symptom::where('appliance_type_id', $fridge->id)->firstOrFail();
        $this->sendUpdate($this->callbackUpdate($admin->telegram_user_id, "draft:symptom:{$symptom->id}"));
        $this->sendUpdate($this->callbackUpdate($admin->telegram_user_id, 'draft:description:skip'));
        $this->sendUpdate($this->messageUpdate($admin->telegram_user_id, 'Иван'));
        $this->sendUpdate($this->messageUpdate($admin->telegram_user_id, '+79161112233'));
        $this->sendUpdate($this->messageUpdate($admin->telegram_user_id, 'Москва, ул. Молодёжная, 14'));
        $this->sendUpdate($this->callbackUpdate($admin->telegram_user_id, 'draft:zone:skip'));
        $this->sendUpdate($this->callbackUpdate($admin->telegram_user_id, 'draft:date:today'));
        $this->sendUpdate($this->callbackUpdate($admin->telegram_user_id, 'draft:slot:custom'));
        $this->sendUpdate($this->messageUpdate($admin->telegram_user_id, '15:00–18:00'));
        $this->sendUpdate($this->callbackUpdate($admin->telegram_user_id, "draft:master:{$master->id}"));
        $this->sendUpdate($this->callbackUpdate($admin->telegram_user_id, 'draft:confirm:create'));

        $order = Order::query()->firstOrFail();
        $this->assertSame(OrderStatus::ASSIGNED, $order->fresh()->status);

        // Принятие и полевой цикл.
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:accept:{$order->number}"));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:depart:{$order->number}"));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:arrive:{$order->number}"));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:diagnose:{$order->number}"));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:diagnosis:{$order->number}:REPAIRABLE"));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:price_start:{$order->number}"));
        $this->sendUpdate($this->messageUpdate($masterUser->telegram_user_id, 'Неисправен насос'));
        $this->sendUpdate($this->messageUpdate($masterUser->telegram_user_id, 'Замена сливного насоса'));
        $this->sendUpdate($this->messageUpdate($masterUser->telegram_user_id, '4000'));
        $this->sendUpdate($this->messageUpdate($masterUser->telegram_user_id, '1500'));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:price_approve:{$order->number}"));

        $this->assertSame(OrderStatus::IN_PROGRESS, $order->fresh()->status);

        // Отчёт + медиа.
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:complete:{$order->number}"));
        $this->sendUpdate($this->messageUpdate($masterUser->telegram_user_id, 'Заменён насос, проверена работа'));
        $this->sendUpdate($this->messageUpdate($masterUser->telegram_user_id, '4000'));
        $this->sendUpdate($this->messageUpdate($masterUser->telegram_user_id, '1500'));
        $this->sendUpdate($this->messageUpdate($masterUser->telegram_user_id, '800'));
        $this->sendUpdate($this->messageUpdate($masterUser->telegram_user_id, '2200'));
        $this->sendUpdate($this->photoUpdate($masterUser->telegram_user_id, 'final-photo'));
        $this->sendUpdate($this->videoUpdate($masterUser->telegram_user_id, 'final-video'));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, 'work_report:media_done'));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, 'work_report:confirm'));

        $order->refresh();
        $this->assertSame(OrderStatus::COMPLETED, $order->status);
        $this->assertSame(5500, $order->final_price);
        $this->assertSame(2, OrderMedia::query()->where('order_id', $order->id)->count());

        // Оплата.
        $this->sendUpdate($this->callbackUpdate($admin->telegram_user_id, "order:pay_full:{$order->number}"));

        $order->refresh();
        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertSame(5500, $order->amount_paid);
    }

    /**
     * Сценарий 2: ADMIN создаёт → MASTER отказывается → ADMIN назначает другого → MASTER принимает.
     */
    public function test_scenario_02_decline_then_reassign_then_accept(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 90201]);
        $firstUser = User::factory()->master()->create(['telegram_user_id' => 90202]);
        $first = Master::factory()->for($firstUser)->create();
        $secondUser = User::factory()->master()->create(['telegram_user_id' => 90203]);
        $second = Master::factory()->for($secondUser)->create();

        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();
        $slot = TimeSlot::first();
        $order = Order::create([
            'customer_name' => 'Клиент', 'customer_phone' => '+79990001122',
            'appliance_type_id' => $fridge->id, 'symptom' => 'Не морозит', 'address' => 'Москва',
            'visit_date' => now()->toDateString(), 'time_slot_label' => $slot->label, 'time_slot_id' => $slot->id,
            'status' => OrderStatus::NEW, 'master_id' => $first->id, 'created_by' => $admin->id,
        ]);
        $order->update(['status' => OrderStatus::ASSIGNED]);

        $this->sendUpdate($this->callbackUpdate($firstUser->telegram_user_id, "order:decline:{$order->number}"));
        $this->sendUpdate($this->callbackUpdate($firstUser->telegram_user_id, "order:decline_reason:{$order->number}:TOO_FAR"));

        $this->assertSame(OrderStatus::MASTER_DECLINED, $order->fresh()->status);

        $this->sendUpdate($this->callbackUpdate($admin->telegram_user_id, "order:reassign:{$order->number}"));
        $this->sendUpdate($this->callbackUpdate($admin->telegram_user_id, "reassign:master:{$order->number}:{$second->id}"));

        $this->assertSame(OrderStatus::ASSIGNED, $order->fresh()->status);
        $this->assertSame($second->id, $order->fresh()->master_id);

        $this->sendUpdate($this->callbackUpdate($secondUser->telegram_user_id, "order:accept:{$order->number}"));

        $this->assertSame(OrderStatus::ACCEPTED, $order->fresh()->status);
    }

    /**
     * Сценарий 3: диагностика → нужна деталь → повторный визит → ремонт.
     */
    public function test_scenario_03_diagnostics_need_part_repeat_visit_repair(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 90301]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 90302]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeOrderAtStatus($admin, $master, OrderStatus::DIAGNOSTICS, withVisit: true);

        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:diagnosis:{$order->number}:NEED_PART"));
        $this->sendUpdate($this->messageUpdate($masterUser->telegram_user_id, 'Термостат'));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, 'part:skip'));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, 'part:skip'));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, 'part:skip'));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, 'part:media_done'));

        $this->assertSame(OrderStatus::WAITING_PART, $order->fresh()->status);

        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:visit_next:{$order->number}"));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, 'visit:date:tomorrow'));
        $slot2 = TimeSlot::query()->orderBy('sort_order', 'desc')->first();
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "visit:slot:{$slot2->id}"));

        $this->assertSame(OrderStatus::ACCEPTED, $order->fresh()->status);
        $this->assertSame(2, OrderVisit::query()->where('order_id', $order->id)->count());

        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:depart:{$order->number}"));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:arrive:{$order->number}"));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:diagnose:{$order->number}"));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:diagnosis:{$order->number}:REPAIRABLE"));

        $this->assertSame(OrderStatus::PRICE_APPROVAL, $order->fresh()->status);
    }

    /**
     * Сценарий 4: мастер приехал → клиент отказался из-за цены.
     */
    public function test_scenario_04_arrived_then_customer_declines_price(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 90401]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 90402]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeOrderAtStatus($admin, $master, OrderStatus::PRICE_APPROVAL);
        $order->update(['labor_price' => 5000, 'parts_sell_price' => 1500, 'estimated_price' => 6500]);

        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:price_decline:{$order->number}"));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:price_decline_reason:{$order->number}:EXPENSIVE"));

        $order->refresh();
        $this->assertSame(OrderStatus::CUSTOMER_CANCELLED, $order->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id, 'new_status' => 'CUSTOMER_CANCELLED', 'comment' => 'Дорого',
        ]);
    }

    /**
     * Сценарий 5: недозвон → повторный контакт → заказ продолжается штатно.
     */
    public function test_scenario_05_no_contact_then_reached_then_order_continues(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 90501]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 90502]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeOrderAtStatus($admin, $master, OrderStatus::ACCEPTED);

        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:no_contact:{$order->number}"));
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:contact_result:{$order->number}:NO_ANSWER"));

        // Заказ остаётся ACCEPTED — недозвон не эскалирован, до порога далеко.
        $this->assertSame(OrderStatus::ACCEPTED, $order->fresh()->status);
        $this->assertSame(1, \App\Models\ContactAttempt::query()->where('order_id', $order->id)->count());

        // До клиента дозвонились — заказ продолжается обычным полевым циклом.
        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:depart:{$order->number}"));

        $this->assertSame(OrderStatus::ON_THE_WAY, $order->fresh()->status);
    }

    /**
     * Сценарий 6 (гарантийный возврат) — не реализовано, см. класс-докблок.
     */
    public function test_scenario_06_warranty_return_not_implemented(): void
    {
        $this->markTestSkipped('Гарантийный заказ (WARRANTY_RETURN) сознательно отложен — см. vault/Открытые вопросы.md.');
    }

    /**
     * Сценарий 7 (CRM недоступна) — не реализовано, см. класс-докблок.
     */
    public function test_scenario_07_crm_outage_not_implemented(): void
    {
        $this->markTestSkipped('CRM-интеграция не входит в реализованные фазы — см. vault/Открытые вопросы.md.');
    }

    /**
     * Сценарий 8: один callback отправлен Telegram повторно → действие выполнено только один раз.
     */
    public function test_scenario_08_duplicate_update_id_applies_action_once(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 90801]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 90802]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeOrderAtStatus($admin, $master, OrderStatus::ASSIGNED);

        $duplicateUpdate = $this->callbackUpdate($masterUser->telegram_user_id, "order:accept:{$order->number}", updateId: 555555);

        $this->sendUpdate($duplicateUpdate);
        $this->sendUpdate($duplicateUpdate);

        $this->assertSame(1, \App\Models\OrderStatusHistory::query()
            ->where('order_id', $order->id)->where('new_status', 'ACCEPTED')->count());
        $this->assertSame(1, \App\Models\TelegramUpdate::query()->where('update_id', 555555)->count());
    }

    /**
     * Сценарий 9: MASTER A пытается открыть Order MASTER B → ACCESS DENIED.
     */
    public function test_scenario_09_master_cannot_access_someone_elses_order(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 90901]);
        $ownerUser = User::factory()->master()->create(['telegram_user_id' => 90902]);
        $owner = Master::factory()->for($ownerUser)->create();
        $order = $this->makeOrderAtStatus($admin, $owner, OrderStatus::ASSIGNED);

        $strangerUser = User::factory()->master()->create(['telegram_user_id' => 90903]);
        Master::factory()->for($strangerUser)->create();

        $this->sendUpdate($this->callbackUpdate($strangerUser->telegram_user_id, "order:accept:{$order->number}"));

        $this->assertSame(OrderStatus::ASSIGNED, $order->fresh()->status);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage')
            && (int) ($r['chat_id'] ?? 0) === $strangerUser->telegram_user_id
            && str_contains($r['text'] ?? '', 'назначена не тебе')
        );
    }

    /**
     * Сценарий 10: мастер отправляет 5 фото и 3 видео → все 8 сохранены → admin видит их.
     */
    public function test_scenario_10_five_photos_and_three_videos_all_saved_and_visible_to_admin(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 91001]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 91002]);
        $master = Master::factory()->for($masterUser)->create();
        $order = $this->makeOrderAtStatus($admin, $master, OrderStatus::IN_PROGRESS);

        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, "order:add_media:{$order->number}"));

        foreach (range(1, 5) as $i) {
            $this->sendUpdate($this->photoUpdate($masterUser->telegram_user_id, "photo-{$i}"));
        }

        foreach (range(1, 3) as $i) {
            $this->sendUpdate($this->videoUpdate($masterUser->telegram_user_id, "video-{$i}"));
        }

        $this->sendUpdate($this->callbackUpdate($masterUser->telegram_user_id, 'media:done'));

        $this->assertSame(8, OrderMedia::query()->where('order_id', $order->id)->count());

        $this->sendUpdate($this->callbackUpdate($admin->telegram_user_id, "order:view_media:{$order->number}"));

        $sentCount = Http::recorded(fn (Request $r) => str_contains($r->url(), 'sendPhoto') || str_contains($r->url(), 'sendVideo'))->count();
        $this->assertSame(8, $sentCount);
    }

    private function makeOrderAtStatus(User $admin, Master $master, OrderStatus $status, bool $withVisit = false): Order
    {
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();
        $slot = TimeSlot::first();

        $order = Order::create([
            'customer_name' => 'Клиент', 'customer_phone' => '+79990001122',
            'appliance_type_id' => $fridge->id, 'symptom' => 'Не морозит', 'address' => 'Москва',
            'visit_date' => now()->toDateString(), 'time_slot_label' => $slot->label, 'time_slot_id' => $slot->id,
            'status' => OrderStatus::NEW, 'master_id' => $master->id, 'created_by' => $admin->id,
        ]);
        $order->update(['status' => $status]);

        if ($withVisit) {
            OrderVisit::query()->create([
                'order_id' => $order->id, 'visit_number' => 1, 'visit_date' => $order->visit_date,
                'time_slot_label' => $order->time_slot_label, 'master_id' => $master->id,
                'status' => 'SCHEDULED', 'reason' => 'Диагностика',
            ]);
        }

        return $order;
    }
}
