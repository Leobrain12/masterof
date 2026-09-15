<?php

namespace Tests\Feature;

use App\Enums\MediaStage;
use App\Enums\MediaType;
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

class MediaFlowTest extends TestCase
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
                'result' => ['file_path' => 'photos/file_1.jpg'],
            ]),
            'api.telegram.org/file/bottest:token/*' => Http::response('fake-binary-bytes'),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
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

    private function postPhoto(int $telegramUserId, string $fileId = 'photo-file-1', int $fileSize = 50_000): void
    {
        $this->postJson(self::WEBHOOK_URL, [
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => 1,
                'from' => ['id' => $telegramUserId, 'is_bot' => false, 'first_name' => 'Test'],
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                'photo' => [
                    ['file_id' => $fileId.'-small', 'file_unique_id' => 'u-small', 'width' => 100, 'height' => 100, 'file_size' => 2000],
                    ['file_id' => $fileId, 'file_unique_id' => 'u-large', 'width' => 800, 'height' => 600, 'file_size' => $fileSize],
                ],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }

    private function postVideo(int $telegramUserId, string $fileId = 'video-file-1', int $fileSize = 1_000_000): void
    {
        $this->postJson(self::WEBHOOK_URL, [
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => 1,
                'from' => ['id' => $telegramUserId, 'is_bot' => false, 'first_name' => 'Test'],
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                'video' => [
                    'file_id' => $fileId,
                    'file_unique_id' => 'vu-1',
                    'width' => 1280,
                    'height' => 720,
                    'duration' => 30,
                    'mime_type' => 'video/mp4',
                    'file_size' => $fileSize,
                ],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }

    /**
     * @return array{0: Order, 1: Master, 2: User}
     */
    private function makeInProgressOrder(int $adminTelegramId, int $masterTelegramId): array
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => $adminTelegramId]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => $masterTelegramId]);
        $master = Master::factory()->for($masterUser)->create();

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
        $order->update(['status' => OrderStatus::IN_PROGRESS]);

        OrderVisit::query()->create([
            'order_id' => $order->id,
            'visit_number' => 1,
            'visit_date' => $order->visit_date,
            'time_slot_label' => $order->time_slot_label,
            'master_id' => $master->id,
            'status' => 'SCHEDULED',
            'reason' => 'Диагностика',
        ]);

        return [$order, $master, $masterUser];
    }

    public function test_standalone_media_collection_stores_photo_and_video_and_downloads_to_private_disk(): void
    {
        [$order, , $masterUser] = $this->makeInProgressOrder(21001, 22001);

        $this->postCallback($masterUser->telegram_user_id, "order:add_media:{$order->number}");
        $this->postPhoto($masterUser->telegram_user_id, 'photo-a');
        $this->postVideo($masterUser->telegram_user_id, 'video-a');
        $this->postCallback($masterUser->telegram_user_id, 'media:done');

        $this->assertSame(2, OrderMedia::query()->where('order_id', $order->id)->count());

        $photo = OrderMedia::query()->where('order_id', $order->id)->where('media_type', MediaType::PHOTO->value)->firstOrFail();
        $this->assertSame('photo-a', $photo->telegram_file_id);
        $this->assertSame(MediaStage::DURING, $photo->stage);
        $this->assertNotNull($photo->storage_path);
        Storage::disk('media')->assertExists($photo->storage_path);
        $this->assertSame('fake-binary-bytes', Storage::disk('media')->get($photo->storage_path));

        $video = OrderMedia::query()->where('order_id', $order->id)->where('media_type', MediaType::VIDEO->value)->firstOrFail();
        $this->assertSame('video-a', $video->telegram_file_id);
        $this->assertSame(30, $video->duration_seconds);
        $this->assertSame('video/mp4', $video->mime_type);

        $this->assertDatabaseMissing('pending_inputs', ['user_id' => $masterUser->id]);
    }

    public function test_album_style_sequence_stores_every_photo_not_just_first(): void
    {
        [$order, , $masterUser] = $this->makeInProgressOrder(21002, 22002);

        $this->postCallback($masterUser->telegram_user_id, "order:add_media:{$order->number}");
        $this->postPhoto($masterUser->telegram_user_id, 'album-1');
        $this->postPhoto($masterUser->telegram_user_id, 'album-2');
        $this->postPhoto($masterUser->telegram_user_id, 'album-3');
        $this->postCallback($masterUser->telegram_user_id, 'media:done');

        $this->assertSame(3, OrderMedia::query()->where('order_id', $order->id)->count());
        $this->assertSame(
            ['album-1', 'album-2', 'album-3'],
            OrderMedia::query()->where('order_id', $order->id)->orderBy('created_at')->pluck('telegram_file_id')->all()
        );
    }

    public function test_video_over_20mb_is_rejected_with_clear_message_and_not_downloaded(): void
    {
        [$order, , $masterUser] = $this->makeInProgressOrder(21003, 22003);

        $this->postCallback($masterUser->telegram_user_id, "order:add_media:{$order->number}");
        $this->postVideo($masterUser->telegram_user_id, 'too-big', fileSize: 21 * 1024 * 1024);

        $this->assertSame(0, OrderMedia::query()->where('order_id', $order->id)->count());

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'getFile'));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', 'Максимальный размер для загрузки в систему — 20 МБ')
        );
    }

    public function test_part_request_media_is_tagged_with_part_stage(): void
    {
        $admin = User::factory()->admin()->create(['telegram_user_id' => 21004]);
        $masterUser = User::factory()->master()->create(['telegram_user_id' => 22004]);
        $master = Master::factory()->for($masterUser)->create();
        $fridge = ApplianceType::where('name', 'Холодильник')->firstOrFail();
        $slot = TimeSlot::first();

        $order = Order::create([
            'customer_name' => 'Клиент', 'customer_phone' => '+79990001122',
            'appliance_type_id' => $fridge->id, 'symptom' => 'Не морозит', 'address' => 'Москва',
            'visit_date' => now()->toDateString(), 'time_slot_label' => $slot->label, 'time_slot_id' => $slot->id,
            'status' => OrderStatus::NEW, 'master_id' => $master->id, 'created_by' => $admin->id,
        ]);
        $order->update(['status' => OrderStatus::DIAGNOSTICS]);

        $this->postCallback($masterUser->telegram_user_id, "order:diagnosis:{$order->number}:NEED_PART");
        $this->postMessage($masterUser->telegram_user_id, 'Термостат');
        $this->postCallback($masterUser->telegram_user_id, 'part:skip');
        $this->postCallback($masterUser->telegram_user_id, 'part:skip');
        $this->postCallback($masterUser->telegram_user_id, 'part:skip');
        $this->postPhoto($masterUser->telegram_user_id, 'part-photo');
        $this->postCallback($masterUser->telegram_user_id, 'part:media_done');

        $order->refresh();
        $this->assertSame(OrderStatus::WAITING_PART, $order->status);

        $media = OrderMedia::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(MediaStage::PART, $media->stage);
        $this->assertNull($media->work_report_id);
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

    public function test_work_report_media_is_linked_to_report_after_confirmation(): void
    {
        [$order, $master, $masterUser] = $this->makeInProgressOrder(21005, 22005);

        $this->postCallback($masterUser->telegram_user_id, "order:complete:{$order->number}");
        $this->postMessage($masterUser->telegram_user_id, 'Заменена деталь');
        $this->postMessage($masterUser->telegram_user_id, '1000');
        $this->postMessage($masterUser->telegram_user_id, '0');
        $this->postMessage($masterUser->telegram_user_id, '0');
        $this->postMessage($masterUser->telegram_user_id, '500');
        $this->postPhoto($masterUser->telegram_user_id, 'after-photo');
        $this->postCallback($masterUser->telegram_user_id, 'work_report:media_done');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage')
            && (int) ($r['chat_id'] ?? 0) === $masterUser->telegram_user_id
            && str_contains($r['text'] ?? '', 'Фото: 1')
        );

        $this->postCallback($masterUser->telegram_user_id, 'work_report:confirm');

        $report = \App\Models\WorkReport::query()->where('order_id', $order->id)->firstOrFail();
        $media = OrderMedia::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame($report->id, $media->work_report_id);
        $this->assertSame(MediaStage::AFTER, $media->stage);
    }

    public function test_media_view_resends_stored_files_via_telegram(): void
    {
        [$order, , $masterUser] = $this->makeInProgressOrder(21006, 22006);

        $this->postCallback($masterUser->telegram_user_id, "order:add_media:{$order->number}");
        $this->postPhoto($masterUser->telegram_user_id, 'view-photo');
        $this->postCallback($masterUser->telegram_user_id, 'media:done');

        $this->postCallback($masterUser->telegram_user_id, "order:view_media:{$order->number}");

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendPhoto')
            && $r['photo'] === 'view-photo'
        );
    }

    public function test_other_master_cannot_view_media_of_someone_elses_order(): void
    {
        [$order, , ] = $this->makeInProgressOrder(21007, 22007);

        $strangerUser = User::factory()->master()->create(['telegram_user_id' => 22008]);
        Master::factory()->for($strangerUser)->create();

        $this->postCallback($strangerUser->telegram_user_id, "order:view_media:{$order->number}");

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', 'назначена не тебе')
        );
    }

    public function test_admin_can_view_media_of_any_order(): void
    {
        [$order, , $masterUser] = $this->makeInProgressOrder(21009, 22009);

        $this->postCallback($masterUser->telegram_user_id, "order:add_media:{$order->number}");
        $this->postPhoto($masterUser->telegram_user_id, 'admin-view-photo');
        $this->postCallback($masterUser->telegram_user_id, 'media:done');

        $admin2 = User::factory()->admin()->create(['telegram_user_id' => 21010]);
        $this->postCallback($admin2->telegram_user_id, "order:view_media:{$order->number}");

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendPhoto')
            && $r['photo'] === 'admin-view-photo'
        );
    }

    public function test_media_unavailable_without_active_session(): void
    {
        [$order, , $masterUser] = $this->makeInProgressOrder(21011, 22011);

        $this->postPhoto($masterUser->telegram_user_id, 'unexpected-photo');

        $this->assertSame(0, OrderMedia::query()->where('order_id', $order->id)->count());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', 'не ожидается')
        );
    }
}
