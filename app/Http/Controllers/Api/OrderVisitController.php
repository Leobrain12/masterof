<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderVisit;
use App\Services\Orders\InvalidOrderTransitionException;
use App\Services\Orders\OrderNotifier;
use App\Services\Orders\OrderStatusMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ТЗ п.88: POST /api/v1/orders/{id}/visits — запланировать повторный визит
 * (ТЗ п.33-34), зеркалит OrderVisitFlow::apply() (используется мастером в
 * боте, когда деталь получена) без Telegram/PendingInput-обвязки вокруг.
 */
class OrderVisitController extends Controller
{
    public function __construct(
        private readonly OrderStatusMachine $statusMachine,
        private readonly OrderNotifier $notifier,
    ) {}

    public function __invoke(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'visit_date' => ['required', 'date'],
            'time_slot_label' => ['required', 'string', 'max:255'],
        ]);

        if ($order->status !== OrderStatus::WAITING_PART) {
            return response()->json([
                'error' => 'Invalid transition',
                'from' => $order->status->value,
                'to' => OrderStatus::ACCEPTED->value,
            ], 409);
        }

        $order->visits()->where('status', 'SCHEDULED')->update([
            'status' => 'COMPLETED',
            'completed_at' => now(),
        ]);

        $nextNumber = (int) ($order->visits()->max('visit_number') ?? 0) + 1;

        OrderVisit::query()->create([
            'order_id' => $order->id,
            'visit_number' => $nextNumber,
            'visit_date' => $data['visit_date'],
            'time_slot_label' => $data['time_slot_label'],
            'master_id' => $order->master_id,
            'status' => 'SCHEDULED',
            'reason' => 'Установка детали: '.$order->part_name,
        ]);

        try {
            $order = $this->statusMachine->transition(
                $order,
                OrderStatus::ACCEPTED,
                actor: null,
                comment: "Визит #{$nextNumber} запланирован (API)",
                attributes: [
                    'visit_date' => $data['visit_date'],
                    'time_slot_label' => $data['time_slot_label'],
                ],
            );
        } catch (InvalidOrderTransitionException $e) {
            return response()->json([
                'error' => 'Invalid transition',
                'from' => $e->order->status->value,
                'to' => $e->attempted->value,
            ], 409);
        }

        $order->load(['master', 'applianceType', 'brand', 'geoZone']);

        $this->notifier->visitScheduled($order, $nextNumber);

        return (new OrderResource($order))->response();
    }
}
