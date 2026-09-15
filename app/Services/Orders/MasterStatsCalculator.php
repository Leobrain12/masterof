<?php

namespace App\Services\Orders;

use App\Models\Master;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Carbon\CarbonInterface;

/**
 * Формулы статистики мастера (ТЗ п.74-75) — зафиксированы один раз здесь,
 * не пересчитываются заново в каждом месте, где нужна цифра.
 *
 * Важное упрощение: "назначено"/"отказано" считаются по ИСТОРИИ статусов
 * (ASSIGNED/MASTER_DECLINED в период), а не по текущему master_id заказа —
 * иначе заказ, переназначенный другому мастеру после отказа, задним числом
 * "исчезал" бы из статистики того, кто отказался. "Выполнено"/"выручка" —
 * наоборот, по ТЕКУЩЕЙ привязке заказа к мастеру: если исполнил — считается
 * за ним, даже если до этого заказ побывал у кого-то другого.
 */
class MasterStatsCalculator
{
    /**
     * @return array{
     *     assigned: int, accepted: int, declined: int, completed: int, paid: int,
     *     customer_cancelled: int, rescheduled: int,
     *     revenue: int, avg_check: int, parts_cost: int, master_payout: int,
     *     completion_rate: ?int, decline_rate: ?int,
     * }
     */
    public function calculate(Master $master, CarbonInterface $from, CarbonInterface $to): array
    {
        $userId = $master->user_id;

        $assigned = OrderStatusHistory::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('new_status', 'ASSIGNED')
            ->whereHas('order', fn ($q) => $q->where('master_id', $master->id))
            ->distinct('order_id')
            ->count('order_id');

        $accepted = OrderStatusHistory::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('new_status', 'ACCEPTED')
            ->where('changed_by_user_id', $userId)
            ->distinct('order_id')
            ->count('order_id');

        $declined = OrderStatusHistory::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('new_status', 'MASTER_DECLINED')
            ->where('changed_by_user_id', $userId)
            ->distinct('order_id')
            ->count('order_id');

        $customerCancelled = OrderStatusHistory::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('new_status', 'CUSTOMER_CANCELLED')
            ->whereHas('order', fn ($q) => $q->where('master_id', $master->id))
            ->distinct('order_id')
            ->count('order_id');

        $rescheduled = OrderStatusHistory::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('comment', 'like', 'Перенос:%')
            ->whereHas('order', fn ($q) => $q->where('master_id', $master->id))
            ->count();

        $finishedIds = Order::query()
            ->where('master_id', $master->id)
            ->whereIn('status', ['COMPLETED', 'PAID'])
            ->whereBetween('completed_at', [$from, $to])
            ->pluck('id');

        $completed = $finishedIds->count();
        $paid = Order::query()->whereIn('id', $finishedIds)->where('status', 'PAID')->count();
        $revenue = (int) Order::query()->whereIn('id', $finishedIds)->sum('final_price');
        $partsCost = (int) Order::query()->whereIn('id', $finishedIds)->sum('parts_cost');
        $masterPayout = (int) Order::query()->whereIn('id', $finishedIds)->sum('master_payout');
        $avgCheck = $completed > 0 ? intdiv($revenue, $completed) : 0;

        return [
            'assigned' => $assigned,
            'accepted' => $accepted,
            'declined' => $declined,
            'completed' => $completed,
            'paid' => $paid,
            'customer_cancelled' => $customerCancelled,
            'rescheduled' => $rescheduled,
            'revenue' => $revenue,
            'avg_check' => $avgCheck,
            'parts_cost' => $partsCost,
            'master_payout' => $masterPayout,
            'completion_rate' => $accepted > 0 ? (int) round($completed / $accepted * 100) : null,
            'decline_rate' => $assigned > 0 ? (int) round($declined / $assigned * 100) : null,
        ];
    }
}
