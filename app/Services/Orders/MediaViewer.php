<?php

namespace App\Services\Orders;

use App\Enums\MediaStage;
use App\Enums\MediaType;
use App\Models\Order;
use App\Models\User;
use App\Services\Telegram\TelegramClient;

/**
 * Просмотр медиа заказа (ТЗ п.49, F6/F13 — "Просмотр медиа в OrderDetail").
 * Пересылает уже загруженные файлы обратно через Telegram по telegram_file_id —
 * это мгновенно (файл уже на серверах Telegram) и не требует обращения к
 * приватному хранилищу, которое существует для надёжности и соответствия
 * 152-ФЗ (ТЗ п.54), а не как источник для показа внутри бота.
 */
class MediaViewer
{
    public function __construct(private readonly TelegramClient $telegram) {}

    public function show(User $viewer, int $chatId, int $orderNumber): void
    {
        $order = Order::query()->with('media')->where('number', $orderNumber)->first();

        if (! $order) {
            $this->telegram->sendMessage($chatId, "Заявка #{$orderNumber} не найдена.");

            return;
        }

        if (! $viewer->role->isAdminLike() && (! $order->master || $order->master->user_id !== $viewer->id)) {
            $this->telegram->sendMessage($chatId, 'Эта заявка назначена не тебе.');

            return;
        }

        if ($order->media->isEmpty()) {
            $this->telegram->sendMessage($chatId, "По заявке {$order->code()} медиа нет.");

            return;
        }

        foreach ($order->media as $item) {
            $method = $item->media_type === MediaType::PHOTO ? 'sendPhoto' : 'sendVideo';
            $field = $item->media_type === MediaType::PHOTO ? 'photo' : 'video';
            $stage = $item->stage instanceof MediaStage ? $item->stage : MediaStage::from($item->stage);

            $this->telegram->call($method, [
                'chat_id' => $chatId,
                $field => $item->telegram_file_id,
                'caption' => $stage->label(),
            ]);
        }
    }
}
