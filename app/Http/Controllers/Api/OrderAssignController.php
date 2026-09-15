<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Master;
use App\Models\Order;
use App\Services\Orders\InvalidOrderTransitionException;
use App\Services\Orders\OrderNotifier;
use App\Services\Orders\OrderStatusMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ТЗ п.88: POST /api/v1/orders/{id}/assign — назначить мастера на заказ,
 * пришедший через API без мастера (см. OrderController::store). Та же пара
 * "статус + master_id" через OrderStatusMachine, что и в OrderReassignFlow
 * для NEW/MASTER_DECLINED в боте — просто без Telegram-обвязки вокруг.
 */
class OrderAssignController extends Controller
{
    public function __construct(
        private readonly OrderStatusMachine $statusMachine,
        private readonly OrderNotifier $notifier,
    ) {}

    public function __invoke(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'master_id' => ['required', 'uuid', 'exists:masters,id'],
        ]);

        $master = Master::query()->findOrFail($data['master_id']);

        try {
            $order = $this->statusMachine->transition(
                $order,
                OrderStatus::ASSIGNED,
                actor: null,
                attributes: ['master_id' => $master->id],
            );
        } catch (InvalidOrderTransitionException $e) {
            return response()->json([
                'error' => 'Invalid transition',
                'from' => $e->order->status->value,
                'to' => $e->attempted->value,
            ], 409);
        }

        $order->load(['master.user', 'applianceType', 'brand', 'geoZone']);

        $this->notifier->assignedToMaster($order);

        return (new OrderResource($order))->response();
    }
}
