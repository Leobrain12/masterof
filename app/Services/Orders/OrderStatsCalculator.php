<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Carbon\CarbonInterface;

/**
 * Сводные показатели по всем заказам за период (ТЗ п.77-79) — то, что видит
 * ADMIN поверх разбивки по мастерам ([[MasterStatsCalculator]]).
 *
 * ТЗ п.65: гарантийный повтор "не считается новым обычным коммерческим
 * заказом" — new_orders/completed/revenue/avg_check исключают заказы с
 * warranty_parent_order_id, вместо этого считаются отдельно (warranty_*).
 */
class OrderStatsCalculator
{
    /**
     * @return array{
     *     new_orders: int, completed: int, revenue: int, avg_check: int,
     *     customer_cancelled: int, master_declines: int, waiting_parts: int,
     *     warranty_orders: int, warranty_rate: ?int, warranty_cost: int,
     * }
     */
    public function calculate(CarbonInterface $from, CarbonInterface $to): array
    {
        $newOrders = Order::query()
            ->whereNull('warranty_parent_order_id')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $finishedIds = Order::query()
            ->whereNull('warranty_parent_order_id')
            ->whereIn('status', ['COMPLETED', 'PAID'])
            ->whereBetween('completed_at', [$from, $to])
            ->pluck('id');

        $completed = $finishedIds->count();
        $revenue = (int) Order::query()->whereIn('id', $finishedIds)->sum('final_price');
        $avgCheck = $completed > 0 ? intdiv($revenue, $completed) : 0;

        $customerCancelled = OrderStatusHistory::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('new_status', 'CUSTOMER_CANCELLED')
            ->distinct('order_id')
            ->count('order_id');

        $masterDeclines = OrderStatusHistory::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('new_status', 'MASTER_DECLINED')
            ->distinct('order_id')
            ->count('order_id');

        // Не привязано к периоду намеренно — это снимок "сколько сейчас висит",
        // а не событие, которое произошло в конкретном интервале времени.
        $waitingParts = Order::query()->where('status', OrderStatus::WAITING_PART->value)->count();

        $warrantyOrders = Order::query()
            ->whereNotNull('warranty_parent_order_id')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        // Доля гарантийных обращений от завершённых обычных заказов за тот же
        // период — сама ТЗ формулу не даёт (п.65 называет только поле
        // warranty_rate), интерпретация зафиксирована здесь же.
        $warrantyRate = $completed > 0 ? (int) round($warrantyOrders / $completed * 100) : null;

        $warrantyFinishedIds = Order::query()
            ->whereNotNull('warranty_parent_order_id')
            ->whereIn('status', ['COMPLETED', 'PAID'])
            ->whereBetween('completed_at', [$from, $to])
            ->pluck('id');

        // Себестоимость гарантийных визитов для бизнеса — запчасти + выплата
        // мастеру, не final_price (гарантийный ремонт обычно ничего не стоит клиенту).
        $warrantyCost = (int) Order::query()->whereIn('id', $warrantyFinishedIds)->sum('parts_cost')
            + (int) Order::query()->whereIn('id', $warrantyFinishedIds)->sum('master_payout');

        return [
            'new_orders' => $newOrders,
            'completed' => $completed,
            'revenue' => $revenue,
            'avg_check' => $avgCheck,
            'customer_cancelled' => $customerCancelled,
            'master_declines' => $masterDeclines,
            'waiting_parts' => $waitingParts,
            'warranty_orders' => $warrantyOrders,
            'warranty_rate' => $warrantyRate,
            'warranty_cost' => $warrantyCost,
        ];
    }
}
