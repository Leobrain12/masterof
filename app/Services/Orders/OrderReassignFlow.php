<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Master;
use App\Models\Order;
use App\Models\User;
use App\Services\Telegram\TelegramClient;

/**
 * Назначение другого мастера на заявку, которая осталась без мастера —
 * NEW (ещё не назначена) или MASTER_DECLINED (отказ, ТЗ п.22.1: "[Назначить
 * другого мастера]"). Чисто на callback_data, без server-side состояния —
 * весь контекст (номер заказа, id мастера) умещается в одной кнопке.
 */
class OrderReassignFlow
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly MasterMatcher $matcher,
        private readonly OrderStatusMachine $statusMachine,
        private readonly OrderNotifier $notifier,
    ) {}

    public function promptMaster(User $admin, int $chatId, int $orderNumber): void
    {
        $order = $this->findReassignableOrder($orderNumber, $chatId);

        if (! $order) {
            return;
        }

        $result = $this->matcher->forOrder($order->appliance_type_id, $order->brand_id, $order->geo_zone_id);
        $masters = $result['masters'];

        if ($masters->isEmpty()) {
            $this->telegram->sendMessage($chatId, 'Нет ни одного активного мастера в системе.');

            return;
        }

        $lines = $result['exact']
            ? ["Подходящие мастера для {$order->code()}:"]
            : ["Точных совпадений нет, показаны все доступные для {$order->code()}:"];

        foreach ($masters as $m) {
            $lines[] = "{$m->name} — ".($m->brands->pluck('name')->join(', ') ?: '—').' · '.($m->geoZones->pluck('name')->join(', ') ?: '—');
        }

        $buttons = $masters
            ->map(fn (Master $m) => [['text' => $m->name, 'callback_data' => "reassign:master:{$orderNumber}:{$m->id}"]])
            ->all();

        $this->telegram->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $buttons]);
    }

    public function assign(User $admin, int $chatId, int $orderNumber, string $masterId): void
    {
        $order = $this->findReassignableOrder($orderNumber, $chatId);
        $master = Master::query()->find($masterId);

        if (! $order || ! $master) {
            return;
        }

        // MASTER_DECLINED сохраняет master_id отказавшегося (см. OrderDecisionFlow) —
        // до transition() это единственный способ отличить "впервые назначаем" (NEW)
        // от "назначаем взамен" (после отказа), чтобы не писать "переназначена" там,
        // где мастера раньше не было вообще.
        $wasReassignment = $order->master_id !== null;

        try {
            $order = $this->statusMachine->transition(
                $order,
                OrderStatus::ASSIGNED,
                $admin,
                attributes: ['master_id' => $master->id],
            );
        } catch (InvalidOrderTransitionException) {
            $this->telegram->sendMessage($chatId, "Заявка {$order->code()} уже изменена. Обновите список заказов.");

            return;
        }

        $order->load('master.user');

        $this->notifier->reassignedByAdmin($order, $admin, $wasReassignment);
        $this->notifier->assignedToMaster($order);
    }

    private function findReassignableOrder(int $orderNumber, int $chatId): ?Order
    {
        $order = Order::query()->where('number', $orderNumber)->first();

        if (! $order) {
            $this->telegram->sendMessage($chatId, "Заявка #{$orderNumber} не найдена.");

            return null;
        }

        if (! in_array($order->status, [OrderStatus::NEW, OrderStatus::MASTER_DECLINED], true)) {
            $this->telegram->sendMessage($chatId, "Заявка {$order->code()} уже в статусе «{$order->status->label()}» — переназначение недоступно.");

            return null;
        }

        return $order;
    }
}
