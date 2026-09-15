<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'lead_id', 'crm_id', 'source',
        'customer_name', 'customer_phone',
        'appliance_type_id', 'brand_id', 'model', 'symptom', 'description',
        'address', 'geo_zone_id', 'address_lat', 'address_lon',
        'visit_date', 'time_slot_label', 'time_slot_id',
        'master_id', 'status',
        'failure_reason', 'work_needed',
        'part_name', 'part_article', 'part_purchase_price', 'part_comment',
        'estimated_price', 'labor_price', 'final_price', 'parts_sell_price', 'parts_cost', 'master_payout', 'amount_paid',
        'admin_comment', 'master_comment',
        'created_by', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'visit_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->number ??= (int) (self::query()->max('number') ?? 4216) + 1;
        });
    }

    public function applianceType(): BelongsTo
    {
        return $this->belongsTo(ApplianceType::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function geoZone(): BelongsTo
    {
        return $this->belongsTo(GeoZone::class);
    }

    public function timeSlot(): BelongsTo
    {
        return $this->belongsTo(TimeSlot::class);
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(Master::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at');
    }

    public function contactAttempts(): HasMany
    {
        return $this->hasMany(ContactAttempt::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(OrderVisit::class)->orderBy('visit_number');
    }

    public function workReports(): HasMany
    {
        return $this->hasMany(WorkReport::class)->orderBy('created_at');
    }

    public function media(): HasMany
    {
        return $this->hasMany(OrderMedia::class)->orderBy('created_at');
    }

    /**
     * "#4217" — формат ссылки на заказ во всех сообщениях бота (ТЗ, повсеместно).
     */
    public function code(): string
    {
        return '#'.$this->number;
    }

    /**
     * ТЗ п.62: amount_due = final_price - amount_paid. Не хранится отдельной
     * колонкой — вычисляется, чтобы не рассинхронизироваться с amount_paid.
     */
    protected function amountDue(): Attribute
    {
        return Attribute::get(fn () => $this->final_price === null
            ? null
            : max(0, $this->final_price - ($this->amount_paid ?? 0))
        );
    }
}
