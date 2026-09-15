<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\InvalidOrderTransitionException;
use App\Services\Orders\OrderStatusMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ТЗ п.89: "Рекомендуется использовать отдельный endpoint... Backend сам проверяет,
 * разрешён ли переход" — то есть именно OrderStatusMachine, не этот контроллер.
 */
class OrderTransitionController extends Controller
{
    public function __construct(private readonly OrderStatusMachine $statusMachine) {}

    public function __invoke(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'target_status' => ['required', 'string'],
            'comment' => ['nullable', 'string'],
        ]);

        $target = OrderStatus::tryFrom($data['target_status']);

        if (! $target) {
            return response()->json(['error' => 'Unknown target_status'], 422);
        }

        try {
            $order = $this->statusMachine->transition($order, $target, actor: null, comment: $data['comment'] ?? null);
        } catch (InvalidOrderTransitionException $e) {
            return response()->json([
                'error' => 'Invalid transition',
                'from' => $e->order->status->value,
                'to' => $e->attempted->value,
            ], 409);
        }

        return response()->json([
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
        ]);
    }
}
