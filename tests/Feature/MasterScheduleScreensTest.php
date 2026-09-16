<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\ApplianceType;
use App\Models\Master;
use App\Models\Order;
use App\Models\TimeSlot;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MasterScheduleScreensTest extends TestCase
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

    private function makeOrder(User $admin, ?Master $master, OrderStatus $status, ?string $visitDate = null): Order
    {
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();
        $slot = TimeSlot::first();

        $order = Order::create([
            'customer_name' => 'Клиент '.$status->value,
            'customer_phone' => '+79990001122',
            'appliance_type_id' => $fridge->id,
            'symptom' => 'Не морозит',
            'address' => 'Москва',
            'visit_date' => $visitDate ?? now()->toDateString(),
            'time_slot_label' => $slot->label,
            'time_slot_id' => $slot->id,
            'status' => OrderStatus::NEW,
            'master_id' => $master?->id,
            'created_by' => $admin->id,
        ]);

        $order->update(['status' => $status]);

        return $order;
    }

    public function test_today_shows_own_visits_scheduled_for_today(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 9101]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 9102]);
        $master = Master::factory()->for($masterUser)->create();

        $today = $this->makeOrder($admin, $master, OrderStatus::ASSIGNED, now()->toDateString());
        $tomorrow = $this->makeOrder($admin, $master, OrderStatus::ASSIGNED, now()->addDay()->toDateString());

        $this->postMessage($masterUser->telegram_user_id, 'Сегодня');

        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', $today->code()));
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', $tomorrow->code()));
    }

    public function test_today_excludes_order_this_master_declined(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 9103]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 9104]);
        $master = Master::factory()->for($masterUser)->create();

        // master_id остаётся на отказавшемся мастере и после MASTER_DECLINED
        // (см. commit d35c89a/22ad30e) — без явного исключения статуса эта
        // заявка утекла бы обратно в «Сегодня» того же мастера.
        $declined = $this->makeOrder($admin, $master, OrderStatus::MASTER_DECLINED, now()->toDateString());

        $this->postMessage($masterUser->telegram_user_id, 'Сегодня');

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $masterUser->telegram_user_id
            && ($r['text'] ?? '') === 'На сегодня заявок нет.'
        );
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', $declined->code()));
    }

    public function test_today_excludes_other_masters_visits(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 9105]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 9106]);
        $master = Master::factory()->for($masterUser)->create();
        $otherUser = User::factory()->master()->create(['telegram_user_id' => 9107]);
        $other = Master::factory()->for($otherUser)->create();

        $mine = $this->makeOrder($admin, $master, OrderStatus::ASSIGNED, now()->toDateString());
        $theirs = $this->makeOrder($admin, $other, OrderStatus::ASSIGNED, now()->toDateString());

        $this->postMessage($masterUser->telegram_user_id, 'Сегодня');

        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', $mine->code()));
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', $theirs->code()));
    }

    public function test_tomorrow_shows_visits_scheduled_for_tomorrow(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 9108]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 9109]);
        $master = Master::factory()->for($masterUser)->create();

        $today = $this->makeOrder($admin, $master, OrderStatus::ASSIGNED, now()->toDateString());
        $tomorrow = $this->makeOrder($admin, $master, OrderStatus::ASSIGNED, now()->addDay()->toDateString());

        $this->postMessage($masterUser->telegram_user_id, 'Завтра');

        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', $tomorrow->code()));
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', $today->code()));
    }

    public function test_history_shows_only_completed_and_paid(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 9110]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 9111]);
        $master = Master::factory()->for($masterUser)->create();

        $completed = $this->makeOrder($admin, $master, OrderStatus::COMPLETED);
        $paid = $this->makeOrder($admin, $master, OrderStatus::PAID);
        $active = $this->makeOrder($admin, $master, OrderStatus::ACCEPTED);
        $declined = $this->makeOrder($admin, $master, OrderStatus::MASTER_DECLINED);

        $this->postMessage($masterUser->telegram_user_id, 'История');

        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', $completed->code()));
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', $paid->code()));
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', $active->code()));
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', $declined->code()));
    }

    public function test_history_empty_state(): void
    {
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 9112]);
        Master::factory()->for($masterUser)->create();

        $this->postMessage($masterUser->telegram_user_id, 'История');

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $masterUser->telegram_user_id
            && ($r['text'] ?? '') === 'История пуста.'
        );
    }

    public function test_admin_cannot_open_master_schedule_screens(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 9113]);

        $this->postMessage($admin->telegram_user_id, 'История');

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && str_contains($r['text'] ?? '', 'следующих фаз')
        );
    }
}
