<?php

namespace App\Services\Orders;

use App\Enums\MediaStage;
use App\Enums\OrderStatus;
use App\Models\PendingInput;
use App\Models\User;
use App\Services\Telegram\TelegramClient;

/**
 * Самостоятельный вход "Добавить медиа" вне заявки на деталь или отчёта —
 * кнопка на IN_PROGRESS (Figma F13: "Добавить медиа", "Завершить ремонт").
 */
class MediaCollectionFlow
{
    private const KIND = 'media_collection';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly MediaCollector $collector,
        private readonly MasterOrderGuard $guard,
    ) {}

    public function start(User $master, int $chatId, int $orderNumber): void
    {
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::IN_PROGRESS, $chatId);

        if (! $order) {
            return;
        }

        PendingInput::query()->updateOrCreate(
            ['user_id' => $master->id],
            [
                'kind' => self::KIND,
                'payload' => ['order_number' => $orderNumber],
                'expires_at' => now()->addMinutes(30),
            ]
        );

        $this->telegram->sendMessage($chatId, 'Пришли фото и/или видео. Когда закончишь — нажми «Медиа закончены».', [
            'inline_keyboard' => [[['text' => 'Медиа закончены', 'callback_data' => 'media:done']]],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $photo
     * @param  array<string, mixed>|null  $video
     */
    public function handleMedia(User $master, PendingInput $pending, int $chatId, ?array $photo, ?array $video): void
    {
        $orderNumber = (int) $pending->get('order_number');
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::IN_PROGRESS, $chatId);

        if (! $order) {
            return;
        }

        $this->collector->handleIncoming($order, $master, MediaStage::DURING, $chatId, $photo, $video);
    }

    public function finish(PendingInput $pending, int $chatId): void
    {
        $pending->delete();
        $this->telegram->sendMessage($chatId, 'Медиа сохранены.');
    }
}
