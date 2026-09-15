<?php

namespace App\Services\Orders;

use App\Enums\MediaStage;
use App\Enums\MediaType;
use App\Models\Order;
use App\Models\OrderMedia;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Приём и сохранение одного фото/видео (ТЗ п.47, 52-55). Общий для всех мест,
 * где заказ принимает медиа — заявка на деталь (фаза 03), отчёт (фаза 04),
 * произвольное "Добавить медиа" на IN_PROGRESS.
 *
 * Альбомы (ТЗ п.53) отдельно не обрабатываются — Telegram присылает каждое фото
 * альбома отдельным апдейтом, а этот класс вызывается на каждый такой апдейт,
 * пока сценарий-вызывающий остаётся в состоянии приёма медиа. Специальной
 * группировки по media_group_id не требуется: главное — не выходить из
 * состояния приёма после первого файла, это и обеспечивает вызывающий flow.
 */
class MediaCollector
{
    private const MAX_VIDEO_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly TelegramClient $telegram,
    ) {}

    /**
     * Разбирает апдейт (photo — массив PhotoSize, video — объект Video), сохраняет
     * и сразу отвечает пользователю результатом — счётчиком или ошибкой лимита.
     *
     * @param  array<int, array<string, mixed>>|null  $photo
     * @param  array<string, mixed>|null  $video
     */
    public function handleIncoming(
        Order $order,
        User $uploader,
        MediaStage $stage,
        int $chatId,
        ?array $photo,
        ?array $video,
        ?string $workReportId = null,
        ?string $visitId = null,
    ): void {
        if (! $photo && ! $video) {
            $this->telegram->sendMessage($chatId, 'Пришли фото или видео, либо нажми «Медиа закончены».');

            return;
        }

        $result = $photo
            ? $this->storePhoto($order, $uploader, $stage, $photo, $workReportId, $visitId)
            : $this->storeVideo($order, $uploader, $stage, $video, $workReportId, $visitId);

        if (is_string($result)) {
            $this->telegram->sendMessage($chatId, $result);

            return;
        }

        $counts = $this->counts($order);
        $label = $result->media_type === MediaType::PHOTO ? 'Фото' : 'Видео';
        $this->telegram->sendMessage($chatId, "{$label} сохранено.\nФото: {$counts['photos']}\nВидео: {$counts['videos']}");
    }

    /**
     * @param  array<int, array<string, mixed>>  $photoSizes
     */
    private function storePhoto(
        Order $order,
        User $uploader,
        MediaStage $stage,
        array $photoSizes,
        ?string $workReportId,
        ?string $visitId,
    ): OrderMedia|string {
        $largest = collect($photoSizes)
            ->sortByDesc(fn ($p) => ($p['width'] ?? 0) * ($p['height'] ?? 0))
            ->first();

        return $this->download(
            order: $order,
            uploader: $uploader,
            stage: $stage,
            type: MediaType::PHOTO,
            fileId: $largest['file_id'],
            fileUniqueId: $largest['file_unique_id'] ?? null,
            fileSize: $largest['file_size'] ?? null,
            width: $largest['width'] ?? null,
            height: $largest['height'] ?? null,
            mimeType: null,
            duration: null,
            workReportId: $workReportId,
            visitId: $visitId,
        );
    }

    /**
     * @param  array<string, mixed>  $video
     */
    private function storeVideo(
        Order $order,
        User $uploader,
        MediaStage $stage,
        array $video,
        ?string $workReportId,
        ?string $visitId,
    ): OrderMedia|string {
        $size = $video['file_size'] ?? null;

        if ($size !== null && $size > self::MAX_VIDEO_BYTES) {
            return "Видео слишком большое.\nМаксимальный размер для загрузки в систему — 20 МБ.\nСожмите видео или отправьте более короткий фрагмент.";
        }

        return $this->download(
            order: $order,
            uploader: $uploader,
            stage: $stage,
            type: MediaType::VIDEO,
            fileId: $video['file_id'],
            fileUniqueId: $video['file_unique_id'] ?? null,
            fileSize: $size,
            width: $video['width'] ?? null,
            height: $video['height'] ?? null,
            mimeType: $video['mime_type'] ?? null,
            duration: $video['duration'] ?? null,
            workReportId: $workReportId,
            visitId: $visitId,
        );
    }

    private function download(
        Order $order,
        User $uploader,
        MediaStage $stage,
        MediaType $type,
        string $fileId,
        ?string $fileUniqueId,
        ?int $fileSize,
        ?int $width,
        ?int $height,
        ?string $mimeType,
        ?int $duration,
        ?string $workReportId,
        ?string $visitId,
    ): OrderMedia|string {
        $downloaded = $this->telegram->downloadFile($fileId);

        if (! $downloaded) {
            return 'Не удалось скачать файл. Попробуй отправить ещё раз.';
        }

        $extension = pathinfo($downloaded['file_path'], PATHINFO_EXTENSION) ?: ($type === MediaType::PHOTO ? 'jpg' : 'mp4');
        $path = "orders/{$order->id}/".Str::uuid()->toString().".{$extension}";

        Storage::disk('media')->put($path, $downloaded['bytes']);

        return OrderMedia::query()->create([
            'order_id' => $order->id,
            'visit_id' => $visitId,
            'work_report_id' => $workReportId,
            'uploaded_by_user_id' => $uploader->id,
            'media_type' => $type,
            'stage' => $stage,
            'telegram_file_id' => $fileId,
            'telegram_file_unique_id' => $fileUniqueId,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'duration_seconds' => $duration,
            'width' => $width,
            'height' => $height,
            'storage_path' => $path,
        ]);
    }

    /**
     * @return array{photos: int, videos: int}
     */
    public function counts(Order $order): array
    {
        return [
            'photos' => $order->media()->where('media_type', MediaType::PHOTO->value)->count(),
            'videos' => $order->media()->where('media_type', MediaType::VIDEO->value)->count(),
        ];
    }
}
