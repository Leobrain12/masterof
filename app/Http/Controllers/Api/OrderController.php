<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Jobs\SyncOrderToCrm;
use App\Models\Order;
use App\Models\OrderVisit;
use App\Services\Orders\OrderNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ТЗ п.88: POST/GET/PATCH /api/v1/orders — приём заказов от CRM/сайта
 * (ТЗ п.17.2: "Website→ Lead→ CRM→ admin qualification→ Order API→ Telegram").
 * Заказ создаётся без мастера (status=NEW) — назначение отдельным вызовом,
 * см. OrderAssignController; ровно так же устроена ручная заявка в боте,
 * только там оба шага происходят в одном диалоге (см. OrderDraftFlow).
 */
class OrderController extends Controller
{
    private const RELATIONS = ['applianceType', 'brand', 'geoZone', 'master'];

    public function __construct(private readonly OrderNotifier $notifier) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:32'],
            'appliance_type_id' => ['required', 'integer', 'exists:appliance_types,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'model' => ['nullable', 'string', 'max:255'],
            'symptom' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'address' => ['required', 'string', 'max:255'],
            'geo_zone_id' => ['nullable', 'integer', 'exists:geo_zones,id'],
            'visit_date' => ['required', 'date'],
            'time_slot_label' => ['required', 'string', 'max:255'],
            'time_slot_id' => ['nullable', 'integer', 'exists:time_slots,id'],
            'lead_id' => ['nullable', 'uuid'],
            'source' => ['nullable', 'string', 'max:255'],
            'admin_comment' => ['nullable', 'string'],
        ]);

        $order = Order::query()->create([
            ...$data,
            'source' => $data['source'] ?? 'api',
            'status' => OrderStatus::NEW,
            'created_by' => null,
        ]);

        // Visit #1 — как и у заявки, созданной в боте (см. OrderDraftFlow), но
        // без мастера: назначение здесь — отдельный шаг (OrderAssignController).
        OrderVisit::query()->create([
            'order_id' => $order->id,
            'visit_number' => 1,
            'visit_date' => $order->visit_date,
            'time_slot_label' => $order->time_slot_label,
            'master_id' => null,
            'status' => 'SCHEDULED',
            'reason' => 'Диагностика',
        ]);

        $this->notifier->orderReceivedViaApi($order);

        // Создание не проходит через OrderStatusMachine::transition() (там нет
        // смены статуса), поэтому синк дёргается явно — иначе CRM узнает про
        // заказ только при первом транзишене (назначении).
        SyncOrderToCrm::dispatchSafely($order);

        return (new OrderResource($order->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Order $order): OrderResource
    {
        return new OrderResource($order->load(self::RELATIONS));
    }

    public function update(Request $request, Order $order): OrderResource
    {
        // Статус, мастер, деньги, crm_id/lead_id — через свои эндпоинты
        // (transition/assign/payments/work-report, сам факт приёма от CRM).
        // PATCH — только для исправления логистических/контактных полей.
        $data = $request->validate([
            'customer_name' => ['sometimes', 'string', 'max:255'],
            'customer_phone' => ['sometimes', 'string', 'max:32'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'brand_id' => ['sometimes', 'nullable', 'integer', 'exists:brands,id'],
            'description' => ['sometimes', 'nullable', 'string'],
            'address' => ['sometimes', 'string', 'max:255'],
            'geo_zone_id' => ['sometimes', 'nullable', 'integer', 'exists:geo_zones,id'],
            'admin_comment' => ['sometimes', 'nullable', 'string'],
        ]);

        $order->update($data);

        SyncOrderToCrm::dispatchSafely($order);

        return new OrderResource($order->load(self::RELATIONS));
    }

    public function search(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'master_id' => ['sometimes', 'uuid'],
            'customer_phone' => ['sometimes', 'string'],
            'number' => ['sometimes', 'integer'],
            'visit_date_from' => ['sometimes', 'date'],
            'visit_date_to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Order::query()->with(self::RELATIONS)->latest('created_at');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['master_id'])) {
            $query->where('master_id', $filters['master_id']);
        }

        if (isset($filters['customer_phone'])) {
            $query->where('customer_phone', 'like', '%'.$filters['customer_phone'].'%');
        }

        if (isset($filters['number'])) {
            $query->where('number', $filters['number']);
        }

        if (isset($filters['visit_date_from'])) {
            $query->whereDate('visit_date', '>=', $filters['visit_date_from']);
        }

        if (isset($filters['visit_date_to'])) {
            $query->whereDate('visit_date', '<=', $filters['visit_date_to']);
        }

        $orders = $query->paginate($filters['per_page'] ?? 20);

        return OrderResource::collection($orders)->response();
    }
}
