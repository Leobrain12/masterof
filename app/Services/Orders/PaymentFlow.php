<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PendingInput;
use App\Models\User;
use App\Services\Telegram\TelegramClient;

/**
 * Отметка оплаты после завершения ремонта (ТЗ п.60-62). Полноценная сущность
 * Payment (история платежей) сознательно отложена — чек-лист прямо разрешает
 * обходиться полями на Order на старте (см. vault/Открытые вопросы). Здесь —
 * разовая фиксация суммы, а не растущий журнал платежей.
 */
class PaymentFlow
{
    private const KIND = 'payment_partial';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OrderStatusMachine $statusMachine,
        private readonly OrderNotifier $notifier,
    ) {}

    public function markFullyPaid(User $admin, int $chatId, int $orderNumber): void
    {
        $order = $this->findCompletedOrder($orderNumber, $chatId);

        if (! $order) {
            return;
        }

        $order->update(['amount_paid' => $order->final_price]);

        try {
            $order = $this->statusMachine->transition($order, OrderStatus::PAID, $admin);
        } catch (InvalidOrderTransitionException) {
            $this->telegram->sendMessage($chatId, "Заявка {$order->code()} уже изменена. Обновите список заказов.");

            return;
        }

        $this->telegram->sendMessage($chatId, "Заявка {$order->code()} отмечена как оплаченная полностью.");
        $this->notifier->paymentRecorded($order, "оплачено полностью, {$order->final_price} ₽");
    }

    public function promptPartialAmount(User $admin, int $chatId, int $orderNumber): void
    {
        if (! $this->findCompletedOrder($orderNumber, $chatId)) {
            return;
        }

        PendingInput::query()->updateOrCreate(
            ['user_id' => $admin->id],
            [
                'kind' => self::KIND,
                'payload' => ['order_number' => $orderNumber],
                'expires_at' => now()->addMinutes(15),
            ]
        );

        $this->telegram->sendMessage($chatId, 'Введите полученную сумму, ₽:');
    }

    public function handlePartialAmountText(User $admin, int $chatId, PendingInput $pending, string $text): void
    {
        $orderNumber = (int) $pending->get('order_number');
        $amount = ctype_digit(trim($text)) ? (int) trim($text) : null;

        if ($amount === null) {
            $this->telegram->sendMessage($chatId, 'Введите число рублей:');

            return;
        }

        $pending->delete();
        $order = $this->findCompletedOrder($orderNumber, $chatId);

        if (! $order) {
            return;
        }

        $order->update(['amount_paid' => $amount]);
        $order->refresh();

        $this->telegram->sendMessage(
            $chatId,
            "Заявка {$order->code()}: получено {$amount} ₽, остаток {$order->amount_due} ₽."
        );

        $this->notifier->paymentRecorded($order, "частично оплачено: {$amount} ₽, остаток {$order->amount_due} ₽");
    }

    public function markUnpaid(User $admin, int $chatId, int $orderNumber): void
    {
        $order = $this->findCompletedOrder($orderNumber, $chatId);

        if (! $order) {
            return;
        }

        $this->telegram->sendMessage($chatId, "Заявка {$order->code()} отмечена как неоплаченная.");
        $this->notifier->paymentRecorded($order, 'не оплачено');
    }

    private function findCompletedOrder(int $orderNumber, int $chatId): ?Order
    {
        $order = Order::query()->where('number', $orderNumber)->first();

        if (! $order) {
            $this->telegram->sendMessage($chatId, "Заявка #{$orderNumber} не найдена.");

            return null;
        }

        if ($order->status !== OrderStatus::COMPLETED) {
            $this->telegram->sendMessage($chatId, "Заявка {$order->code()} не в статусе «Выполнен» — отметка оплаты недоступна.");

            return null;
        }

        return $order;
    }
}
