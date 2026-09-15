<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderVisit extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'order_id', 'visit_number', 'visit_date', 'time_slot_label',
        'master_id', 'status', 'reason', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(Master::class);
    }
}
