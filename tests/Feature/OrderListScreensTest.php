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

class OrderListScreensTest extends TestCase
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

    private function makeOrder(User $admin, ?Master $master, OrderStatus $status, ?string $visitDate = null, ?string $phone = null): Order
    {
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();
        $slot = TimeSlot::first();

        $order = Order::create([
            'customer_name' => 'Клиент '.$status->value,
            'customer_phone' => $phone ?? '+79990001122',
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

    public function test_unassigned_screen_shows_new_and_declined_orders_only(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 5001]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 6001]);
        $master = Master::factory()->for($masterUser)->create();

        $new = $this->makeOrder($admin, null, OrderStatus::NEW);
        $declined = $this->makeOrder($admin, $master, OrderStatus::MASTER_DECLINED);
        $assigned = $this->makeOrder($admin, $master, OrderStatus::ASSIGNED);
        $accepted = $this->makeOrder($admin, $master, OrderStatus::ACCEPTED);

        $this->postMessage($admin->telegram_user_id, 'Нераспределённые');

        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', $new->code()) && str_contains($r['reply_markup'] ?? '', 'Назначить мастера'));
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', $declined->code()));
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', $assigned->code()));
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', $accepted->code()));
    }

    public function test_active_screen_shows_every_status_still_in_motion(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 5002]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 6002]);
        $master = Master::factory()->for($masterUser)->create();

        $assigned = $this->makeOrder($admin, $master, OrderStatus::ASSIGNED);
        $accepted = $this->makeOrder($admin, $master, OrderStatus::ACCEPTED);
        $diagnostics = $this->makeOrder($admin, $master, OrderStatus::DIAGNOSTICS);
        $inProgress = $this->makeOrder($admin, $master, OrderStatus::IN_PROGRESS);
        $waitingPart = $this->makeOrder($admin, $master, OrderStatus::WAITING_PART);
        $completed = $this->makeOrder($admin, $master, OrderStatus::COMPLETED);

        $new = $this->makeOrder($admin, null, OrderStatus::NEW);
        $declined = $this->makeOrder($admin, $master, OrderStatus::MASTER_DECLINED);
        $paid = $this->makeOrder($admin, $master, OrderStatus::PAID);
        $cancelled = $this->makeOrder($admin, $master, OrderStatus::CUSTOMER_CANCELLED);

        $this->postMessage($admin->telegram_user_id, 'Активные');

        foreach ([$assigned, $accepted, $diagnostics, $inProgress, $waitingPart, $completed] as $order) {
            Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', $order->code()));
        }

        foreach ([$new, $declined, $paid, $cancelled] as $order) {
            Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', $order->code()));
        }
    }

    public function test_master_cannot_open_admin_order_lists(): void
    {
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 6003]);

        $this->postMessage($masterUser->telegram_user_id, 'Нераспределённые');

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $masterUser->telegram_user_id
            && str_contains($r['text'] ?? '', 'следующих фаз')
        );
    }

    public function test_empty_lists_show_empty_state(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 5003]);

        $this->postMessage($admin->telegram_user_id, 'Нераспределённые');

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && ($r['text'] ?? '') === 'Нераспределённых заявок нет.'
        );
    }

    public function test_today_screen_shows_only_todays_visits_regardless_of_status(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 5004]);

        $today = $this->makeOrder($admin, null, OrderStatus::NEW, now()->toDateString());
        $tomorrow = $this->makeOrder($admin, null, OrderStatus::NEW, now()->addDay()->toDateString());

        $this->postMessage($admin->telegram_user_id, 'Сегодня');

        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', $today->code()));
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', $tomorrow->code()));
    }

    public function test_today_screen_shows_empty_state(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 5005]);

        $this->postMessage($admin->telegram_user_id, 'Сегодня');

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && ($r['text'] ?? '') === 'На сегодня заявок нет.'
        );
    }

    public function test_search_by_order_number_finds_exact_match_only(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 5006]);

        $target = $this->makeOrder($admin, null, OrderStatus::NEW);
        $other = $this->makeOrder($admin, null, OrderStatus::NEW);

        $this->postMessage($admin->telegram_user_id, 'Поиск');
        $this->assertDatabaseHas('pending_inputs', ['user_id' => $admin->id, 'kind' => 'order_search']);

        $this->postMessage($admin->telegram_user_id, '#'.$target->number);

        $this->assertDatabaseCount('pending_inputs', 0);
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', $target->code()));
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', $other->code()));
    }

    public function test_search_by_phone_matches_substring(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 5007]);

        $target = $this->makeOrder($admin, null, OrderStatus::NEW, phone: '+79161112233');
        $other = $this->makeOrder($admin, null, OrderStatus::NEW, phone: '+79995554433');

        $this->postMessage($admin->telegram_user_id, 'Поиск');
        $this->postMessage($admin->telegram_user_id, '+7 916 111-22-33');

        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', $target->code()));
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', $other->code()));
    }

    public function test_search_with_no_matches_shows_empty_state(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 5008]);

        $this->postMessage($admin->telegram_user_id, 'Поиск');
        $this->postMessage($admin->telegram_user_id, '+79990000000');

        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $admin->telegram_user_id
            && ($r['text'] ?? '') === 'Ничего не найдено.'
        );
    }
}
