<?php

namespace App\Models;

use App\Enums\ContactAttemptResult;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactAttempt extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['order_id', 'user_id', 'attempted_at', 'result', 'comment'];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
            'result' => ContactAttemptResult::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
