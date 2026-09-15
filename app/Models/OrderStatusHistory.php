<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusHistory extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['order_id', 'old_status', 'new_status', 'changed_by_user_id', 'comment', 'created_at'];

    protected function casts(): array
    {
        return [
            'old_status' => OrderStatus::class,
            'new_status' => OrderStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
