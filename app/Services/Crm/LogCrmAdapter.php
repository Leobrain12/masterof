<?php

namespace App\Services\Crm;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Реализация по умолчанию, пока реальная CRM не подключена (ТЗ п.87: "CRM не
 * является single point of failure" — значит система обязана нормально
 * работать и вовсе без неё). Просто логирует снимок заказа и ничего никуда
 * не отправляет — не бросает исключений, синк всегда "успешен".
 *
 * Когда появится реальная CRM (Bitrix24, amoCRM, другая) — новый класс,
 * реализующий CrmAdapter, подключается через CRM_ADAPTER_CLASS в .env,
 * без изменений в OrderStatusMachine/SyncOrderToCrm/контроллерах.
 */
class LogCrmAdapter implements CrmAdapter
{
    public function syncOrder(Order $order): ?string
    {
        Log::info('crm.sync', [
            'order_id' => $order->id,
            'order_number' => $order->number,
            'lead_id' => $order->lead_id,
            'crm_id' => $order->crm_id,
            'master_id' => $order->master_id,
            'status' => $order->status->value,
            'visit_date' => $order->visit_date?->toDateString(),
            'time_slot_label' => $order->time_slot_label,
            'failure_reason' => $order->failure_reason,
            'work_needed' => $order->work_needed,
            'estimated_price' => $order->estimated_price,
            'final_price' => $order->final_price,
            'parts_sell_price' => $order->parts_sell_price,
            'parts_cost' => $order->parts_cost,
            'amount_paid' => $order->amount_paid,
            'amount_due' => $order->amount_due,
            'completed_at' => $order->completed_at?->toIso8601String(),
        ]);

        return null;
    }
}
