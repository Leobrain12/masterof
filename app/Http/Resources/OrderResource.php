<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Общий JSON-снимок заказа для Internal API (ТЗ п.88) — используется всеми
 * write-эндпоинтами (store/assign/visits/work-report/payments/transition)
 * и read-эндпоинтами (show/search), чтобы CRM видела один и тот же формат
 * независимо от того, какой вызов его вернул.
 *
 * @mixin \App\Models\Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'code' => $this->code(),
            'status' => $this->status->value,
            'source' => $this->source,
            'lead_id' => $this->lead_id,
            'crm_id' => $this->crm_id,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'appliance_type' => $this->whenLoaded('applianceType', fn () => [
                'id' => $this->applianceType->id,
                'name' => $this->applianceType->name,
            ]),
            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ] : null),
            'model' => $this->model,
            'symptom' => $this->symptom,
            'description' => $this->description,
            'address' => $this->address,
            'geo_zone' => $this->whenLoaded('geoZone', fn () => $this->geoZone ? [
                'id' => $this->geoZone->id,
                'name' => $this->geoZone->name,
            ] : null),
            'visit_date' => $this->visit_date?->toDateString(),
            'time_slot_label' => $this->time_slot_label,
            'master' => $this->whenLoaded('master', fn () => $this->master ? [
                'id' => $this->master->id,
                'name' => $this->master->name,
                'phone' => $this->master->phone,
            ] : null),
            'failure_reason' => $this->failure_reason,
            'work_needed' => $this->work_needed,
            'estimated_price' => $this->estimated_price,
            'final_price' => $this->final_price,
            'parts_sell_price' => $this->parts_sell_price,
            'parts_cost' => $this->parts_cost,
            'master_payout' => $this->master_payout,
            'amount_paid' => $this->amount_paid,
            'amount_due' => $this->amount_due,
            'admin_comment' => $this->admin_comment,
            'master_comment' => $this->master_comment,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
