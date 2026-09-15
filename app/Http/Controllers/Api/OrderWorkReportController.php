<?php

namespace App\Http\Controllers\Api;

use App\Enums\MediaStage;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderMedia;
use App\Models\WorkReport;
use App\Services\Orders\InvalidOrderTransitionException;
use App\Services\Orders\OrderNotifier;
use App\Services\Orders\OrderStatusMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ТЗ п.88: POST /api/v1/orders/{id}/work-report — завершить ремонт, зеркалит
 * WorkReportFlow::confirm() (используется мастером в боте) без Telegram/
 * PendingInput-обвязки вокруг. final_price считается, а не принимается —
 * та же гарантия целостности, что и в боте (не может разойтись с суммой
 * работ и запчастей).
 */
class OrderWorkReportController extends Controller
{
    public function __construct(
        private readonly OrderStatusMachine $statusMachine,
        private readonly OrderNotifier $notifier,
    ) {}

    public function __invoke(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'work_description' => ['required', 'string'],
            'labor_price' => ['required', 'integer', 'min:0'],
            'parts_sell_price' => ['required', 'integer', 'min:0'],
            'parts_cost' => ['required', 'integer', 'min:0'],
            'master_payout' => ['required', 'integer', 'min:0'],
            'comment' => ['nullable', 'string'],
        ]);

        if ($order->status !== OrderStatus::IN_PROGRESS) {
            return response()->json([
                'error' => 'Invalid transition',
                'from' => $order->status->value,
                'to' => OrderStatus::COMPLETED->value,
            ], 409);
        }

        $finalPrice = $data['labor_price'] + $data['parts_sell_price'];
        $currentVisit = $order->visits()->where('status', 'SCHEDULED')->latest('visit_number')->first();

        try {
            $order = $this->statusMachine->transition(
                $order,
                OrderStatus::COMPLETED,
                actor: null,
                attributes: [
                    'final_price' => $finalPrice,
                    'parts_sell_price' => $data['parts_sell_price'],
                    'parts_cost' => $data['parts_cost'],
                    'master_payout' => $data['master_payout'],
                    'master_comment' => $data['work_description'],
                    'completed_at' => now(),
                ],
            );
        } catch (InvalidOrderTransitionException $e) {
            return response()->json([
                'error' => 'Invalid transition',
                'from' => $e->order->status->value,
                'to' => $e->attempted->value,
            ], 409);
        }

        $currentVisit?->update(['status' => 'COMPLETED', 'completed_at' => now()]);

        $report = WorkReport::query()->create([
            'order_id' => $order->id,
            'visit_id' => $currentVisit?->id,
            'master_id' => $order->master_id,
            'failure_reason' => $order->failure_reason,
            'work_description' => $data['work_description'],
            'labor_price' => $data['labor_price'],
            'parts_sell_price' => $data['parts_sell_price'],
            'parts_cost' => $data['parts_cost'],
            'final_price' => $finalPrice,
            'master_payout' => $data['master_payout'],
            'comment' => $data['comment'] ?? null,
            'confirmed_at' => now(),
        ]);

        // Медиа могло прийти отдельным вызовом (POST .../media, stage=AFTER)
        // до work-report — id отчёта тогда ещё не было, привязываем задним числом.
        OrderMedia::query()
            ->where('order_id', $order->id)
            ->where('stage', MediaStage::AFTER->value)
            ->whereNull('work_report_id')
            ->update(['work_report_id' => $report->id]);

        $order->load(['master', 'applianceType', 'brand', 'geoZone']);

        $this->notifier->orderCompleted($order, $report);

        return (new OrderResource($order))->response();
    }
}
