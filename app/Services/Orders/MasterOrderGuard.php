<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Telegram\TelegramClient;

/**
 * Общая проверка "эта заявка назначена именно этому мастеру и находится в ожидаемом
 * статусе" (ТЗ п.91-92, п.112) — переиспользуется всеми flow фазы 02, чтобы не
 * копировать одну и ту же проверку в каждом из них.
 */
class MasterOrderGuard
{
    public function __construct(private readonly TelegramClient $telegram) {}

    public function resolve(User $master, int $orderNumber, OrderStatus $expected, int $chatId): ?Order
    {
        return $this->resolveAny($master, $orderNumber, [$expected], $chatId);
    }

    /**
     * @param  list<OrderStatus>  $expected
     */
    public function resolveAny(User $master, int $orderNumber, array $expected, int $chatId): ?Order
    {
        $order = Order::query()->with('master')->where('number', $orderNumber)->first();

        if (! $order) {
            $this->telegram->sendMessage($chatId, "Заявка #{$orderNumber} не найдена.");

            return null;
        }

        if (! $order->master || $order->master->user_id !== $master->id) {
            $this->telegram->sendMessage($chatId, 'Эта заявка назначена не тебе.');

            return null;
        }

        if (! in_array($order->status, $expected, true)) {
            $this->telegram->sendMessage($chatId, "Заявка {$order->code()} уже изменена. Обновите список заказов.");

            return null;
        }

        return $order;
    }
}
