<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use RuntimeException;

/**
 * Заказ уже сменил статус (гонка админа/мастера, ТЗ п.112) либо запрошен
 * переход, которого нет в графе состояний.
 */
class InvalidOrderTransitionException extends RuntimeException
{
    public function __construct(public readonly Order $order, public readonly OrderStatus $attempted)
    {
        parent::__construct(
            "Order {$order->code()} cannot transition from {$order->status->value} to {$attempted->value}"
        );
    }
}
