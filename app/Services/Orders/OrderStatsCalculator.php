<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Carbon\CarbonInterface;

/**
 * Сводные показатели по всем заказам за период (ТЗ п.77-79) — то, что видит
 * ADMIN поверх разбивки по мастерам ([[MasterStatsCalculator]]).
 */
class OrderStatsCalculator
{
    /**
     * @return array{
     *     new_orders: int, completed: int, revenue: int, avg_check: int,
     *     customer_cancelled: int, master_declines: int, waiting_parts: int,
     * }
     */
    public function calculate(CarbonInterface $from, CarbonInterface $to): array
    {
        $newOrders = Order::query()->whereBetween('created_at', [$from, $to])->count();

        $finishedIds = Order::query()
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

        return [
            'new_orders' => $newOrders,
            'completed' => $completed,
            'revenue' => $revenue,
            'avg_check' => $avgCheck,
            'customer_cancelled' => $customerCancelled,
            'master_declines' => $masterDeclines,
            'waiting_parts' => $waitingParts,
        ];
    }
}
