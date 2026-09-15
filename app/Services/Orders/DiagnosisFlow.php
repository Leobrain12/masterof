<?php

namespace App\Services\Orders;

use App\Enums\DiagnosisOutcome;
use App\Enums\OrderStatus;
use App\Models\User;
use App\Services\Telegram\TelegramClient;

/**
 * Начало диагностики и её результат — 4-way ветвление (ТЗ п.26-28). "Нужна деталь"
 * делегируется в PartRequestFlow — переход в WAITING_PART там, в конце сбора данных
 * о детали, а не сразу здесь. Остальные три исхода фиксируются сменой статуса
 * и комментарием немедленно.
 */
class DiagnosisFlow
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OrderStatusMachine $statusMachine,
        private readonly OrderNotifier $notifier,
        private readonly MasterOrderGuard $guard,
        private readonly MasterOrderScreen $screen,
        private readonly PartRequestFlow $partRequestFlow,
    ) {}

    public function start(User $master, int $chatId, int $orderNumber): void
    {
        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::ARRIVED, $chatId);

        if (! $order) {
            return;
        }

        try {
            $order = $this->statusMachine->transition($order, OrderStatus::DIAGNOSTICS, $master);
        } catch (InvalidOrderTransitionException) {
            $this->telegram->sendMessage($chatId, "Заявка {$order->code()} уже изменена. Обновите список заказов.");

            return;
        }

        $order->load('master');
        $this->notifier->diagnosticsStarted($order);
        $this->screen->sendCard($chatId, $order);
    }

    public function resolve(User $master, int $chatId, int $orderNumber, string $outcomeCode): void
    {
        $outcome = DiagnosisOutcome::tryFrom($outcomeCode);

        if (! $outcome) {
            return;
        }

        if ($outcome === DiagnosisOutcome::NEED_PART) {
            $this->partRequestFlow->start($master, $chatId, $orderNumber);

            return;
        }

        $order = $this->guard->resolve($master, $orderNumber, OrderStatus::DIAGNOSTICS, $chatId);

        if (! $order) {
            return;
        }

        try {
            $order = $this->statusMachine->transition(
                $order,
                $outcome->targetStatus(),
                $master,
                comment: $outcome->label(),
            );
        } catch (InvalidOrderTransitionException) {
            $this->telegram->sendMessage($chatId, "Заявка {$order->code()} уже изменена. Обновите список заказов.");

            return;
        }

        $order->load('master');

        if ($outcome === DiagnosisOutcome::CUSTOMER_DECLINED) {
            $this->notifier->customerCancelled($order, 'Отказ на этапе диагностики');
        } else {
            $this->notifier->diagnosisResult($order, $outcome->label());
        }

        if ($outcome === DiagnosisOutcome::REPAIRABLE) {
            $this->screen->sendCard($chatId, $order);

            return;
        }

        $this->telegram->sendMessage($chatId, "Статус заявки {$order->code()}: {$order->status->label()}.");
    }
}
