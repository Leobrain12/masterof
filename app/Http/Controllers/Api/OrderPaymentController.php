<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\InvalidOrderTransitionException;
use App\Services\Orders\OrderNotifier;
use App\Services\Orders\OrderStatusMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ТЗ п.88: POST /api/v1/orders/{id}/payments — зеркалит PaymentFlow (используется
 * администратором в боте) без Telegram-обвязки вокруг. amount >= final_price —
 * оплачено полностью (переход в PAID, как и markFullyPaid в боте); меньше —
 * частично, статус остаётся COMPLETED, как и в боте markUnpaid/partial.
 */
class OrderPaymentController extends Controller
{
    public function __construct(
        private readonly OrderStatusMachine $statusMachine,
        private readonly OrderNotifier $notifier,
    ) {}

    public function __invoke(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:0'],
        ]);

        if ($order->status !== OrderStatus::COMPLETED) {
            return response()->json([
                'error' => 'Order is not in COMPLETED status',
                'status' => $order->status->value,
            ], 409);
        }

        $amount = $data['amount'];
        $order->update(['amount_paid' => $amount]);
        $order->refresh();

        if ($amount > 0 && $amount >= $order->final_price) {
            try {
                $order = $this->statusMachine->transition($order, OrderStatus::PAID, actor: null);
            } catch (InvalidOrderTransitionException $e) {
                return response()->json([
                    'error' => 'Invalid transition',
                    'from' => $e->order->status->value,
                    'to' => $e->attempted->value,
                ], 409);
            }

            $this->notifier->paymentRecorded($order, "оплачено полностью, {$order->final_price} ₽");
        } elseif ($amount > 0) {
            $this->notifier->paymentRecorded($order, "частично оплачено: {$amount} ₽, остаток {$order->amount_due} ₽");
        } else {
            $this->notifier->paymentRecorded($order, 'не оплачено');
        }

        $order->load(['master', 'applianceType', 'brand', 'geoZone']);

        return (new OrderResource($order))->response();
    }
}
