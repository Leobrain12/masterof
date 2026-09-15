<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkReport extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'order_id', 'visit_id', 'master_id', 'failure_reason', 'work_description',
        'labor_price', 'parts_sell_price', 'parts_cost', 'final_price', 'master_payout',
        'comment', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(OrderVisit::class, 'visit_id');
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(Master::class);
    }
}
