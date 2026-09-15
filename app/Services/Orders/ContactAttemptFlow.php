<?php

namespace App\Services\Orders;

use App\Enums\ContactAttemptResult;
use App\Enums\OrderStatus;
use App\Models\ContactAttempt;
use App\Models\Order;
use App\Models\User;
use App\Services\Telegram\TelegramClient;

/**
 * Недозвон (ТЗ п.36-38). Порог "рекомендации закрытия" — настройка по ТЗ (п.38),
 * но экрана настроек ещё нет ни в одной фазе, поэтому пока константа
 * (см. vault/Открытые вопросы.md → "max_contact_attempts").
 */
class ContactAttemptFlow
{
    private const ESCALATION_THRESHOLD = 3;

    /**
     * @var list<OrderStatus>
     */
    private const APPLICABLE_STATUSES = [OrderStatus::ACCEPTED, OrderStatus::ON_THE_WAY];

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OrderStatusMachine $statusMachine,
        private readonly OrderNotifier $notifier,
        private readonly MasterOrderGuard $guard,
    ) {}

    public function promptReason(User $master, int $chatId, int $orderNumber): void
    {
        if (! $this->guard->resolveAny($master, $orderNumber, self::APPLICABLE_STATUSES, $chatId)) {
            return;
        }

        $buttons = array_map(
            fn (ContactAttemptResult $r) => [['text' => $r->label(), 'callback_data' => "order:contact_result:{$orderNumber}:{$r->value}"]],
            ContactAttemptResult::cases()
        );

        $this->telegram->sendMessage($chatId, 'Результат попытки связаться с клиентом:', ['inline_keyboard' => $buttons]);
    }

    public function record(User $master, int $chatId, int $orderNumber, string $resultCode): void
    {
        $result = ContactAttemptResult::tryFrom($resultCode);
        $order = $this->guard->resolveAny($master, $orderNumber, self::APPLICABLE_STATUSES, $chatId);

        if (! $order || ! $result) {
            return;
        }

        ContactAttempt::query()->create([
            'order_id' => $order->id,
            'user_id' => $master->id,
            'result' => $result,
        ]);

        $count = ContactAttempt::query()->where('order_id', $order->id)->count();

        $this->telegram->sendMessage($chatId, 'Попытка зафиксирована.');
        $this->notifier->contactAttemptRecorded($order, $result, $count, self::ESCALATION_THRESHOLD);
    }

    public function escalate(User $admin, int $chatId, int $orderNumber): void
    {
        $order = Order::query()->where('number', $orderNumber)->first();

        if (! $order) {
            $this->telegram->sendMessage($chatId, "Заявка #{$orderNumber} не найдена.");

            return;
        }

        try {
            $order = $this->statusMachine->transition($order, OrderStatus::NO_CONTACT, $admin);
        } catch (InvalidOrderTransitionException) {
            $this->telegram->sendMessage($chatId, "Заявка {$order->code()} уже изменена. Обновите список заказов.");

            return;
        }

        $this->notifier->noContactEscalated($order);
    }
}
