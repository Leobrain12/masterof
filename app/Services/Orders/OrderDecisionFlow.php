<?php

namespace App\Services\Orders;

use App\Enums\OrderDeclineReason;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PendingInput;
use App\Models\User;
use App\Services\Telegram\TelegramClient;

/**
 * Реакция мастера на назначенную заявку — принять или отказаться с причиной
 * (ТЗ п.21-22.1). "Другая причина" ждёт свободный текст через PendingInput,
 * остальные причины применяются сразу по нажатию кнопки.
 */
class OrderDecisionFlow
{
    private const PENDING_KIND = 'order_decline_reason';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OrderStatusMachine $statusMachine,
        private readonly OrderNotifier $notifier,
        private readonly MasterOrderGuard $guard,
    ) {}

    public function accept(User $master, int $chatId, int $orderNumber): void
    {
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::ASSIGNED, $chatId);

        if (! $order) {
            return;
        }

        try {
            $order = $this->statusMachine->transition($order, OrderStatus::ACCEPTED, $master);
        } catch (InvalidOrderTransitionException) {
            $this->telegram->sendMessage($chatId, "Заявка #{$orderNumber} уже изменена администратором. Обновите список заказов.");

            return;
        }

        $order->load('master');
        $this->telegram->sendMessage($chatId, "Заявка {$order->code()} принята.");
        $this->notifier->masterAccepted($order);
    }

    public function declineStart(User $master, int $chatId, int $orderNumber): void
    {
        if (! $this->guard->resolve($master, $orderNumber, OrderStatus::ASSIGNED, $chatId)) {
            return;
        }

        $reasons = OrderDeclineReason::cases();
        $buttons = array_map(
            fn (OrderDeclineReason $r) => [['text' => $r->label(), 'callback_data' => "order:decline_reason:{$orderNumber}:{$r->value}"]],
            $reasons
        );

        $this->telegram->sendMessage($chatId, 'Причина отказа:', ['inline_keyboard' => $buttons]);
    }

    public function declineReason(User $master, int $chatId, int $orderNumber, string $reasonCode): void
    {
        $reason = OrderDeclineReason::tryFrom($reasonCode);
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::ASSIGNED, $chatId);

        if (! $order || ! $reason) {
            return;
        }

        if ($reason === OrderDeclineReason::OTHER) {
            PendingInput::query()->updateOrCreate(
                ['user_id' => $master->id],
                [
                    'kind' => self::PENDING_KIND,
                    'payload' => ['order_number' => $orderNumber],
                    'expires_at' => now()->addMinutes(15),
                ]
            );

            $this->telegram->sendMessage($chatId, 'Опиши причину отказа своими словами:');

            return;
        }

        $this->applyDecline($master, $chatId, $order, $reason, null);
    }

    public function declineCustomReasonText(User $master, int $chatId, PendingInput $pending, string $text): void
    {
        $orderNumber = (int) $pending->payload['order_number'];
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::ASSIGNED, $chatId);
        $pending->delete();

        if (! $order) {
            return;
        }

        $this->applyDecline($master, $chatId, $order, OrderDeclineReason::OTHER, trim($text));
    }

    private function applyDecline(User $master, int $chatId, Order $order, OrderDeclineReason $reason, ?string $comment): void
    {
        try {
            $order = $this->statusMachine->transition(
                $order,
                OrderStatus::MASTER_DECLINED,
                $master,
                comment: $reason->label().($comment ? ": {$comment}" : ''),
            );
        } catch (InvalidOrderTransitionException) {
            $this->telegram->sendMessage($chatId, "Заявка {$order->code()} уже изменена администратором. Обновите список заказов.");

            return;
        }

        $this->telegram->sendMessage($chatId, "Отказ по заявке {$order->code()} зафиксирован.");
        $this->notifier->masterDeclined($order, $reason, $comment);
    }
}
