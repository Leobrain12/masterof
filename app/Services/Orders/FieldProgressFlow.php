<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\User;
use App\Services\Telegram\TelegramClient;

/**
 * Простые одношаговые переходы полевого цикла — выезд и прибытие (ТЗ п.24-25).
 * Начало диагностики — в DiagnosisFlow, там переход сразу ведёт в ветвление.
 */
class FieldProgressFlow
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OrderStatusMachine $statusMachine,
        private readonly OrderNotifier $notifier,
        private readonly MasterOrderGuard $guard,
        private readonly MasterOrderScreen $screen,
    ) {}

    public function depart(User $master, int $chatId, int $orderNumber): void
    {
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::ACCEPTED, $chatId);

        if (! $order) {
            return;
        }

        try {
            $order = $this->statusMachine->transition($order, OrderStatus::ON_THE_WAY, $master);
        } catch (InvalidOrderTransitionException) {
            $this->telegram->sendMessage($chatId, "Заявка {$order->code()} уже изменена. Обновите список заказов.");

            return;
        }

        $order->load('master');
        $this->notifier->masterDeparted($order);
        $this->screen->sendCard($chatId, $order);
    }

    public function arrive(User $master, int $chatId, int $orderNumber): void
    {
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::ON_THE_WAY, $chatId);

        if (! $order) {
            return;
        }

        try {
            $order = $this->statusMachine->transition($order, OrderStatus::ARRIVED, $master);
        } catch (InvalidOrderTransitionException) {
            $this->telegram->sendMessage($chatId, "Заявка {$order->code()} уже изменена. Обновите список заказов.");

            return;
        }

        $order->load('master');
        $this->notifier->masterArrived($order);
        $this->screen->sendCard($chatId, $order);
    }
}
