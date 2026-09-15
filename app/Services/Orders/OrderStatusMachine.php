<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Единственное место, где заказу разрешено менять статус (ТЗ п.15, 89: "Backend сам
 * проверяет, разрешён ли переход" — не UI, не клиент). Каждый переход пишется в
 * OrderStatusHistory (ТЗ п.16).
 *
 * Граф ниже покрывает только то, что уже реализовано (создание, назначение, приём/отказ
 * мастера, переназначение). Переходы для фазы 02+ (выезд, диагностика, ...) добавляются
 * сюда вместе с экранами, которые их вызывают — раньше времени граф не угадать.
 */
class OrderStatusMachine
{
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'NEW' => ['ASSIGNED'],
        'ASSIGNED' => ['ACCEPTED', 'MASTER_DECLINED'],
        'MASTER_DECLINED' => ['ASSIGNED'],
        'ACCEPTED' => ['ON_THE_WAY', 'NO_CONTACT'],
        'ON_THE_WAY' => ['ARRIVED', 'NO_CONTACT'],
        'ARRIVED' => ['DIAGNOSTICS'],
        'DIAGNOSTICS' => ['PRICE_APPROVAL', 'WAITING_PART', 'UNREPAIRABLE', 'CUSTOMER_CANCELLED'],
        'PRICE_APPROVAL' => ['IN_PROGRESS', 'CUSTOMER_CANCELLED'],
        // Деталь получена, повторный визит запланирован — сразу ACCEPTED, а не ASSIGNED:
        // это тот же мастер продолжает уже принятую им работу, не новое назначение,
        // заново принимать заявку незачем (см. vault/Фазы/Фаза 03).
        'WAITING_PART' => ['ACCEPTED'],
        'IN_PROGRESS' => ['COMPLETED'],
        'COMPLETED' => ['PAID'],
    ];

    public function canTransition(Order $order, OrderStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$order->status->value] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $attributes  Дополнительные поля заказа, которые нужно
     *                                             сохранить вместе со сменой статуса (например master_id).
     *
     * @throws InvalidOrderTransitionException
     */
    public function transition(
        Order $order,
        OrderStatus $to,
        ?User $actor,
        ?string $comment = null,
        array $attributes = [],
    ): Order {
        return DB::transaction(function () use ($order, $to, $actor, $comment, $attributes) {
            // SELECT ... FOR UPDATE — вторая параллельная попытка (ТЗ п.112) ждёт здесь,
            // а не читает устаревший статус до начала своей проверки.
            $fresh = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $this->canTransition($fresh, $to)) {
                throw new InvalidOrderTransitionException($fresh, $to);
            }

            $from = $fresh->status;

            $fresh->fill($attributes);
            $fresh->status = $to;
            $fresh->save();

            OrderStatusHistory::query()->create([
                'order_id' => $fresh->id,
                'old_status' => $from,
                'new_status' => $to,
                'changed_by_user_id' => $actor?->id,
                'comment' => $comment,
            ]);

            return $fresh;
        });
    }
}
